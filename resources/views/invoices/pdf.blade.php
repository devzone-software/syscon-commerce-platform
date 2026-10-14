<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>{{ $title }} {{ $invoice->serial_number }}</title>
<style>
    @page { margin: 18mm 15mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #111; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .issuer strong { font-size: 13pt; }
    .box { border: 1.5px solid #111; text-align: center; padding: 8px; width: 34%; }
    .box div { margin: 3px 0; }
    .box .type { font-size: 11pt; font-weight: bold; text-transform: uppercase; }
    .box .serial { font-size: 12pt; font-weight: bold; }
    .warning { margin: 10px 0; padding: 6px; border: 1.5px solid #b00020; color: #b00020; font-weight: bold; text-align: center; text-transform: uppercase; }
    .data { margin-top: 12px; }
    .data td { padding: 2px 0; }
    .data th { text-align: left; width: 28%; font-weight: bold; padding: 2px 0; }
    .lines { margin-top: 12px; }
    .lines th { background: #eee; border-bottom: 1px solid #111; padding: 5px 4px; font-size: 8pt; text-align: left; }
    .lines td { border-bottom: 1px solid #ddd; padding: 5px 4px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .totals { margin-top: 10px; width: 45%; margin-left: 55%; }
    .totals td { padding: 3px 4px; }
    .totals tr.total td { border-top: 1.5px solid #111; font-weight: bold; font-size: 10pt; }
    .foot { margin-top: 18px; font-size: 8pt; color: #444; }
    small { color: #555; }
</style>
</head>
<body>
@php($money = fn ($v) => 'S/ '.number_format((float) $v, 2, '.', ','))
@php($documents = ['1' => 'DNI', '4' => 'Carné de extranjería', '6' => 'RUC', '7' => 'Pasaporte'])
<table class="head">
    <tr>
        <td class="issuer">
            <strong>{{ $issuer['name'] }}</strong><br>
            @if($issuer['address']){{ $issuer['address'] }}<br>@endif
        </td>
        <td class="box">
            <div>RUC {{ $issuer['ruc'] ?: '—' }}</div>
            <div class="type">{{ $title }}</div>
            <div class="serial">{{ $invoice->serial_number }}</div>
        </td>
    </tr>
</table>

@if($warning)
    <div class="warning">{{ $warning }}</div>
@endif

<table class="data">
    <tr><th>Fecha de emisión</th><td>{{ $issued->format('d/m/Y H:i') }}</td></tr>
    <tr><th>{{ $invoice->type === '01' || $invoice->customer_document_type === '6' ? 'Razón social' : 'Cliente' }}</th><td>{{ $invoice->customer_name }}</td></tr>
    <tr><th>{{ $documents[$invoice->customer_document_type] ?? 'Documento' }}</th><td>{{ $invoice->customer_document_number }}</td></tr>
    <tr><th>Moneda</th><td>Soles (PEN)</td></tr>
    @if($invoice->type === '01')
        <tr><th>Forma de pago</th><td>Contado</td></tr>
    @endif
    @if($invoice->referenced_serial_number)
        <tr><th>Documento que modifica</th><td>{{ $invoice->referenced_serial_number }}</td></tr>
        <tr><th>Motivo</th><td>{{ $invoice->note_code }}@if($reason) · {{ $reason }}@endif @if($invoice->note_reason)<br><small>{{ $invoice->note_reason }}</small>@endif</td></tr>
    @endif
</table>

<table class="lines">
    <thead>
        <tr>
            <th>#</th>
            <th>Descripción</th>
            <th class="num">Cant.</th>
            <th>Unidad</th>
            <th class="num">Valor unit.</th>
            <th class="num">Precio unit.</th>
            <th class="num">IGV</th>
            <th class="num">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $line)
            <tr>
                <td>{{ $line->line_number }}</td>
                <td>{{ $line->description }}@if($line->sku)<br><small>SKU {{ $line->sku }}</small>@endif</td>
                <td class="num">{{ $line->quantity }}</td>
                <td>{{ $line->unit_code === 'NIU' ? 'Unidad' : 'Servicio' }}</td>
                <td class="num">{{ number_format((float) $unitValue($line), 2, '.', ',') }}</td>
                <td class="num">{{ number_format((float) $line->unit_price, 2, '.', ',') }}</td>
                <td class="num">{{ number_format((float) $line->tax_amount, 2, '.', ',') }}</td>
                <td class="num">{{ number_format((float) $line->line_total, 2, '.', ',') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Op. gravada</td><td class="num">{{ $money($invoice->taxable_amount) }}</td></tr>
    <tr><td>IGV ({{ $percent }}%)</td><td class="num">{{ $money($invoice->tax_amount) }}</td></tr>
    <tr class="total"><td>Importe total</td><td class="num">{{ $money($invoice->total) }}</td></tr>
</table>

<div class="foot">
    @if($warning)
        Este documento no es una representación impresa válida de un comprobante electrónico.
    @else
        Representación impresa de la {{ mb_strtolower($title) }}.
    @endif
    @if($hash)
        <br>Valor resumen: {{ $hash }}
    @endif
    <br>Precio unitario con IGV; valor unitario sin IGV.
</div>
</body>
</html>
