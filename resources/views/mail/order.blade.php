<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:24px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#111c2d;">
@php($money = fn ($v) => 'S/ '.number_format((float) $v, 2, '.', ','))
@php($short = strtoupper(substr($order['id'], 0, 8)))
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;padding:28px;">
    <p style="margin:0 0 18px;font-size:18px;font-weight:bold;color:#00265d;">{{ config('commerce.sunat.legal_name') ?: config('app.name') }}</p>

    @if($kind === 'ORDER_CREATED')
        <h1 style="font-size:20px;margin:0 0 12px;">Recibimos tu pedido {{ $short }}</h1>
        <p>Reservamos el stock de tus productos. El pedido se confirma cuando verifiquemos tu pago.</p>
        @if($order['expiresAt'])
            <p>La reserva vence el <strong>{{ \Illuminate\Support\Carbon::parse($order['expiresAt'])->setTimezone('America/Lima')->format('d/m/Y H:i') }}</strong>; si el pago no se confirma antes, el pedido se cancela.</p>
        @endif
        @if($instructions !== '')
            <p style="margin-bottom:4px;"><strong>Cómo pagar</strong></p>
            <p style="white-space:pre-line;margin-top:0;">{{ $instructions }}</p>
        @endif
    @elseif($kind === 'PAYMENT_CONFIRMED')
        <h1 style="font-size:20px;margin:0 0 12px;">Confirmamos el pago de tu pedido {{ $short }}</h1>
        <p>Ya estamos preparando tu pedido. Te avisaremos cuando tu comprobante esté disponible.</p>
    @else
        <h1 style="font-size:20px;margin:0 0 12px;">Tu {{ mb_strtolower($title) }} {{ $invoice->serial_number }} está disponible</h1>
        <p>Adjuntamos la representación impresa en PDF. También puedes descargarla, con su XML, desde el detalle de tu pedido.</p>
        @if($warning)
            <p style="padding:8px;border:1px solid #b00020;color:#b00020;font-weight:bold;">{{ $warning }}</p>
        @endif
    @endif

    <table style="width:100%;border-collapse:collapse;margin:18px 0;font-size:14px;">
        @foreach($order['details'] as $line)
            <tr>
                <td style="padding:6px 0;border-bottom:1px solid #e5e8ee;">{{ $line['quantity'] }} × {{ $line['name'] }}</td>
                <td style="padding:6px 0;border-bottom:1px solid #e5e8ee;text-align:right;">{{ $money($line['lineTotal'] ?? $line['unitPrice'] * $line['quantity']) }}</td>
            </tr>
        @endforeach
        @if(($order['delivery']['cost'] ?? '0.00') !== '0.00')
            <tr><td style="padding:6px 0;">Envío</td><td style="padding:6px 0;text-align:right;">{{ $money($order['delivery']['cost']) }}</td></tr>
        @endif
        <tr>
            <td style="padding:8px 0;font-weight:bold;">{{ $invoice ? 'Total del comprobante' : 'Total' }}</td>
            <td style="padding:8px 0;text-align:right;font-weight:bold;">{{ $money($invoice ? $invoice->total : $order['total']) }}</td>
        </tr>
    </table>

    @if($link)
        <p><a href="{{ $link }}" style="display:inline-block;padding:12px 20px;background:#00265d;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;">Ver mi pedido</a></p>
    @endif
    <p style="font-size:12px;color:#59616e;margin-top:24px;">Recibes este correo porque realizaste una compra con esta dirección. Precios con IGV incluido.</p>
</div>
</body>
</html>
