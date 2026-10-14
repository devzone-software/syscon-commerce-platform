<?php

namespace App\Modules\Orders;

/**
 * IGV de una venta interna gravada. Los precios del catálogo y el costo de envío incluyen el
 * impuesto (es el precio que ve y paga el cliente), así que el total cobrado no cambia: de cada
 * importe se separa la base redondeada a céntimos y el IGV es la diferencia. Así base + IGV
 * suman exactamente el importe por línea y por documento.
 */
final class Tax
{
    public static function rate(): string
    {
        return bcadd((string) config('commerce.tax.igv_rate', '0.18'), '0', 4);
    }

    /** @return array{0: string, 1: string} [base imponible, IGV] de un importe con IGV incluido. */
    public static function split(string $gross, string $rate): array
    {
        $base = self::round(bcdiv($gross, bcadd('1', $rate, 4), 10), 2);

        return [$base, bcsub($gross, $base, 2)];
    }

    /** Valor unitario sin IGV, con los decimales que admite el XML. */
    public static function unitValue(string $unitPrice, string $rate): string
    {
        return self::round(bcdiv($unitPrice, bcadd('1', $rate, 4), 12), 10);
    }

    /** Redondeo comercial (mitad hacia arriba) de un importe no negativo. */
    public static function round(string $value, int $scale): string
    {
        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }
}
