<?php

namespace App\Modules\Reports;

use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController
{
    /** Período más largo de una consulta, en días. */
    public const MAX_DAYS = 366;

    public function __construct(private ReportService $reports) {}

    public function summary(Request $r)
    {
        [$from, $to] = $this->period($r);

        return Api::ok('Resumen', $this->reports->summary($from, $to));
    }

    /** Ventas del período, paginadas; `format=csv` devuelve todas las filas para hoja de cálculo. */
    public function sales(Request $r)
    {
        [$from, $to] = $this->period($r);
        $data = $r->validate(['page' => 'nullable|integer|min:1', 'perPage' => 'nullable|integer|min:1|max:100', 'mismatched' => 'nullable|boolean', 'format' => 'nullable|in:json,csv']);
        $rows = $this->reports->rows($from, $to, (bool) ($data['mismatched'] ?? false));
        if (($data['format'] ?? 'json') === 'csv') {
            return $this->csv($rows, "ventas-$from-a-$to.csv");
        }
        $perPage = (int) ($data['perPage'] ?? 20);
        $page = (int) ($data['page'] ?? 1);

        return Api::ok('Ventas', ['items' => $rows->forPage($page, $perPage)->map(fn ($row) => ReportService::view($row))->values()->all(),
            'page' => $page, 'perPage' => $perPage, 'total' => $rows->count()]);
    }

    /** @return array{0: string, 1: string} Fechas de Lima; por defecto los últimos 30 días. */
    private function period(Request $r): array
    {
        $data = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $today = Carbon::now(ReportService::TZ);
        $to = $data['to'] ?? $today->toDateString();
        $from = $data['from'] ?? Carbon::parse($to, ReportService::TZ)->subDays(29)->toDateString();
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= self::MAX_DAYS, 422, 'El período no puede superar '.self::MAX_DAYS.' días');

        return [$from, $to];
    }

    /** CSV con punto y coma y BOM, como lo abre Excel en configuración regional de Perú. */
    private function csv($rows, string $name)
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, ['Fecha de pago', 'Pedido', 'Canal', 'Estado', 'Correo', 'Cliente', 'Documento', 'Op. gravada', 'IGV', 'Envío', 'Total', 'Pago aprobado', 'Referencias de pago', 'Comprobantes', 'Facturado neto', 'Observaciones'], ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, [$row['paidAt']->format('Y-m-d H:i'), $row['orderId'], $row['channel'], $row['status'], $row['customerEmail'], $row['billingName'], $row['billingDocument'],
                $row['taxableAmount'], $row['taxAmount'], $row['deliveryCost'], $row['total'], $row['paidAmount'], implode(' ', $row['paymentReferences']),
                implode(' ', array_map(fn ($i) => $i['serialNumber'].' ('.$i['status'].')', $row['invoices'])), $row['netInvoiced'], implode(' ', $row['issues'])], ';', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store']);
    }
}
