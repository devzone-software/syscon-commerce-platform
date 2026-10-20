<?php

namespace App\Modules\Invoices;

use App\Modules\Orders\Tax;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Archivos de un comprobante: la representación impresa (PDF), el XML y el CDR. El PDF se arma
 * con las mismas líneas e importes guardados que el XML y declara siempre su estado: un borrador,
 * un rechazo o un documento aceptado por el servicio de pruebas no tienen valor tributario.
 */
class InvoiceFiles
{
    public const TITLES = ['01' => 'Factura electrónica', '03' => 'Boleta de venta electrónica', '07' => 'Nota de crédito electrónica', '08' => 'Nota de débito electrónica'];

    /** Estado que se imprime en el documento; null solo para un comprobante válido de producción. */
    public static function warning(object $invoice): ?string
    {
        return match (true) {
            $invoice->status === 'REJECTED' => 'Rechazado por SUNAT — sin valor tributario',
            $invoice->status === 'VOIDED' => 'Anulado ante SUNAT — sin valor tributario',
            $invoice->status === 'VOIDING' => 'En anulación ante SUNAT',
            $invoice->status === 'PENDING_CHECK' => 'Pendiente de confirmación de SUNAT',
            $invoice->status !== 'ACCEPTED' => 'Borrador — no enviado a SUNAT, sin valor tributario',
            self::testing() => 'Documento de prueba — sin valor tributario',
            default => null,
        };
    }

    /** El envío apunta al servicio beta de SUNAT: lo aceptado ahí es solo una prueba. */
    public static function testing(): bool
    {
        return str_contains(strtolower((string) config('commerce.sunat.endpoint')), 'beta');
    }

    public static function filename(object $invoice, string $extension): string
    {
        $ruc = (string) config('commerce.sunat.ruc') ?: 'SYSCON';

        return $ruc.'-'.$invoice->type.'-'.$invoice->serial_number.'.'.$extension;
    }

    public function pdf(object $invoice): Response
    {
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'fontCache' => storage_path('framework/cache'), 'tempDir' => sys_get_temp_dir()]);
        $pdf->loadHtml($this->html($invoice), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $this->file($pdf->output(), 'application/pdf', self::filename($invoice, 'pdf'), true);
    }

    /** HTML de la representación impresa, antes de convertirlo a PDF. */
    public function html(object $invoice): string
    {
        $lines = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->orderBy('line_number')->get();
        $signed = DB::table('xml_documents')->where('invoice_id', $invoice->id)->value('signed_xml');

        return view('invoices.pdf', [
            'invoice' => $invoice, 'lines' => $lines, 'title' => self::TITLES[$invoice->type],
            'warning' => self::warning($invoice), 'hash' => $invoice->status === 'ACCEPTED' ? self::digest($signed) : null,
            'qr' => $invoice->status === 'ACCEPTED' ? self::qr($invoice) : null, 'words' => AmountInWords::soles((string) $invoice->total),
            'issued' => Carbon::parse($invoice->created_at)->setTimezone('America/Lima'),
            'percent' => rtrim(rtrim(bcmul((string) $invoice->tax_rate, '100', 2), '0'), '.'),
            'reason' => $invoice->note_code ? (($invoice->type === '07' ? InvoiceLines::CREDIT_REASONS : InvoiceLines::DEBIT_REASONS)[$invoice->note_code] ?? null) : null,
            'unitValue' => fn ($line) => Tax::unitValue((string) $line->unit_price, (string) $invoice->tax_rate),
            'issuer' => ['ruc' => (string) config('commerce.sunat.ruc'), 'name' => (string) config('commerce.sunat.legal_name'), 'address' => (string) config('commerce.sunat.address')],
        ])->render();
    }

    /** XML firmado si ya se envió; si no, el XML generado del borrador. */
    public function xml(object $invoice): Response
    {
        $doc = DB::table('xml_documents')->where('invoice_id', $invoice->id)->first();
        abort_unless($doc, 404, 'XML no generado');

        return $this->file($doc->signed_xml ?? $doc->generated_xml, 'application/xml', self::filename($invoice, 'xml'), false);
    }

    /** Constancia de recepción (ZIP) tal como la devolvió SUNAT. */
    public function cdr(object $invoice): Response
    {
        $zip = DB::table('cdr_responses')->where('invoice_id', $invoice->id)->value('zip_base64');
        abort_unless($zip, 404, 'El comprobante no tiene CDR');

        return $this->file((string) base64_decode($zip, true), 'application/zip', 'R-'.self::filename($invoice, 'zip'), false);
    }

    /**
     * Contenido del código QR de la representación impresa (Anexo B, aspectos técnicos):
     * RUC | tipo | serie | número | IGV | total | fecha de emisión | tipo y número de documento del
     * adquirente |. El valor resumen y la firma solo van en el PDF417, no en el QR.
     */
    public static function qrText(object $invoice): string
    {
        [$series, $number] = explode('-', $invoice->serial_number, 2);

        return implode('|', [config('commerce.sunat.ruc'), $invoice->type, $series, $number, bcadd((string) $invoice->tax_amount, '0', 2), bcadd((string) $invoice->total, '0', 2),
            Carbon::parse($invoice->created_at)->setTimezone('America/Lima')->toDateString(), $invoice->customer_document_type, $invoice->customer_document_number]).'|';
    }

    /** QR en SVG como data URI para el PDF: UTF-8 y corrección de errores nivel Q, como pide SUNAT. */
    private static function qr(object $invoice): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(140, 1), new SvgImageBackEnd)))->writeString(self::qrText($invoice), 'UTF-8', ErrorCorrectionLevel::Q());

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** Valor resumen (DigestValue) de la firma, que la representación impresa muestra. */
    private static function digest(?string $signed): ?string
    {
        if (! $signed || ! preg_match('#<(?:ds:)?DigestValue>([^<]+)</(?:ds:)?DigestValue>#', $signed, $m)) {
            return null;
        }

        return trim($m[1]);
    }

    private function file(string $bytes, string $type, string $name, bool $inline): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
