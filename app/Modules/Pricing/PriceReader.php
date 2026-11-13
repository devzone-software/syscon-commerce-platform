<?php

namespace App\Modules\Pricing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lee el precio público de una página de producto. Usa los datos que las tiendas publican
 * para buscadores (JSON-LD schema.org, metadatos product:price, itemprop="price"), que son
 * más estables que el diseño visible de la página.
 */
class PriceReader
{
    private const MAX_BYTES = 3_000_000;

    /** HTTPS, sin usuario ni puerto raro, del dominio de la empresa (o un subdominio suyo). */
    public static function validateUrl(string $url, string $host): void
    {
        $parts = parse_url($url);
        $urlHost = strtolower($parts['host'] ?? '');
        $host = strtolower($host);
        abort_unless(
            $parts && ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && ($urlHost === $host || str_ends_with($urlHost, '.'.$host)),
            422,
            "El enlace debe empezar con https:// y ser de $host."
        );
    }

    /** Dominio limpio a partir de lo que escribe el personal ("https://www.x.pe/..." o "x.pe"). */
    public static function normalizeHost(string $value): string
    {
        $value = strtolower(trim($value));
        $host = parse_url(str_contains($value, '://') ? $value : "https://$value", PHP_URL_HOST) ?: '';
        $host = preg_replace('/^www\./', '', $host);
        abort_unless((bool) preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $host), 422, 'Escribe el dominio de la web, por ejemplo empresa.com.pe.');

        return $host;
    }

    /** Precio en soles leído de la página; lanza RuntimeException con un motivo legible. */
    public function read(string $url): float
    {
        $this->guardAgainstPrivateHosts($url);
        try {
            $response = Http::timeout(12)->connectTimeout(6)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SysconPriceCheck/1.0)', 'Accept' => 'text/html,application/xhtml+xml'])
                ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']], 'stream' => true])
                ->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('No se pudo conectar con la página.');
        }
        if (! $response->successful()) {
            throw new RuntimeException("La página respondió {$response->status()}.");
        }
        $body = $response->toPsrResponse()->getBody();
        $html = '';
        while (! $body->eof() && strlen($html) < self::MAX_BYTES) {
            $html .= $body->read(65536);
        }
        $price = self::extract($html);
        if ($price === null) {
            throw new RuntimeException('No se encontró el precio en la página. Escríbelo a mano.');
        }

        return $price;
    }

    /** Precio de una página HTML, o null. Se exporta para probarlo sin red. */
    public static function extract(string $html): ?float
    {
        // 1) JSON-LD: Product → offers (Offer, lista de Offer o AggregateOffer).
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                $data = json_decode(html_entity_decode(trim($json)), true);
                if (is_array($data) && ($price = self::fromJsonLd($data)) !== null) {
                    return $price;
                }
            }
        }
        // 2) Metadatos Open Graph / Facebook de producto.
        foreach (['product:price:amount', 'og:price:amount'] as $property) {
            if (preg_match('#<meta[^>]+(?:property|name)=["\']'.preg_quote($property, '#').'["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $m)
                || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']'.preg_quote($property, '#').'["\']#i', $html, $m)) {
                if (($price = self::number($m[1])) !== null) {
                    return $price;
                }
            }
        }
        // 3) Microdatos: itemprop="price" con content=, o su texto.
        if (preg_match('#itemprop=["\']price["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $m)
            || preg_match('#content=["\']([^"\']+)["\'][^>]*itemprop=["\']price["\']#i', $html, $m)
            || preg_match('#itemprop=["\']price["\'][^>]*>\s*([^<]+)<#i', $html, $m)) {
            return self::number($m[1]);
        }

        return null;
    }

    private static function fromJsonLd(array $data): ?float
    {
        // Un bloque puede ser un objeto, una lista o un @graph con varios nodos.
        $nodes = array_is_list($data) ? $data : [$data];
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            $nodes = array_merge($nodes, $data['@graph']);
        }
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $types = (array) ($node['@type'] ?? []);
            if (! in_array('Product', $types, true) || ! isset($node['offers'])) {
                continue;
            }
            $offers = $node['offers'];
            $offers = is_array($offers) && array_is_list($offers) ? $offers : [$offers];
            $prices = [];
            foreach ($offers as $offer) {
                if (! is_array($offer)) {
                    continue;
                }
                $currency = strtoupper((string) ($offer['priceCurrency'] ?? 'PEN'));
                if ($currency !== 'PEN') {
                    continue; // La referencia es en soles; no se convierten monedas.
                }
                $value = $offer['price'] ?? $offer['lowPrice'] ?? $offer['priceSpecification']['price'] ?? null;
                if (($price = self::number((string) $value)) !== null) {
                    $prices[] = $price;
                }
            }
            if ($prices) {
                return min($prices);
            }
        }

        return null;
    }

    /** "1,299.90", "1.299,90", "S/ 1 299.90" o "1299.9" → 1299.9. */
    public static function number(string $text): ?float
    {
        $text = preg_replace('/[^\d.,]/', '', $text);
        if ($text === '' || ! preg_match('/\d/', $text)) {
            return null;
        }
        $lastDot = strrpos($text, '.');
        $lastComma = strrpos($text, ',');
        if ($lastDot !== false && $lastComma !== false) {
            // El separador que aparece al final es el decimal.
            $decimal = $lastDot > $lastComma ? '.' : ',';
            $text = str_replace($decimal === '.' ? ',' : '.', '', $text);
            $text = str_replace(',', '.', $text);
        } elseif ($lastComma !== false) {
            // Solo comas: decimal si van 1 o 2 cifras después ("49,90"), si no son miles ("1,299").
            $text = strlen($text) - $lastComma - 1 <= 2 ? str_replace(',', '.', $text) : str_replace(',', '', $text);
        } elseif ($lastDot !== false && substr_count($text, '.') > 1) {
            $text = str_replace('.', '', $text);
        }
        $value = (float) $text;

        return $value > 0 && $value < 10_000_000 ? round($value, 2) : null;
    }

    /** Evita que un enlace lleve al servidor a su propia red interna. */
    private function guardAgainstPrivateHosts(string $url): void
    {
        if (! config('commerce.pricing_check_dns', true)) {
            return;
        }
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $ips = gethostbynamel($host) ?: [];
        if (! $ips) {
            throw new RuntimeException('No se encontró el dominio de la página.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('La página apunta a una red interna.');
            }
        }
    }
}
