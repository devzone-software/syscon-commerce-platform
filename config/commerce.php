<?php

return [
    'jwt_secret' => env('JWT_SECRET', ''), 'service_token' => env('SERVICE_TOKEN', ''),
    'supplier_hosts' => array_filter(array_map('trim', explode(',', strtolower(env('SUPPLIER_ALLOWED_HOSTS', ''))))),
    'admin_email' => env('ADMIN_EMAIL'), 'admin_password' => env('ADMIN_PASSWORD'),
    // Entrega: el retiro en tienda es gratuito; el envío a domicilio solo se ofrece con DELIVERY_FEE.
    'delivery' => [
        'pickup_address' => env('PICKUP_ADDRESS', ''), 'pickup_hours' => env('PICKUP_HOURS', ''),
        'fee' => env('DELIVERY_FEE', ''), 'areas' => env('DELIVERY_AREAS', ''),
    ],
    // Reserva de stock de un pedido impago: al vencer se libera (0 desactiva el vencimiento).
    'orders' => ['reservation_hours' => (int) env('ORDER_RESERVATION_HOURS', 48)],
    // Texto que ve el cliente en su solicitud de pago manual (cuentas, CCI, a quién avisar).
    // Web de la tienda: los correos enlazan al detalle del pedido ({web_url}/pedido/{id}).
    'web_url' => env('WEB_URL', ''),
    'payments' => ['manual_instructions' => str_replace('\\n', "\n", (string) env('MANUAL_PAYMENT_INSTRUCTIONS', ''))],
    // Los precios del catálogo y el envío incluyen IGV; esta tasa separa base e impuesto de cada venta.
    'tax' => ['igv_rate' => env('IGV_RATE', '0.18')],
    'sunat' => [
        'ruc' => env('SUNAT_RUC', ''), 'legal_name' => env('SUNAT_LEGAL_NAME', 'SYSCON'),
        'user' => env('SUNAT_SOL_USER', ''), 'password' => env('SUNAT_SOL_PASSWORD', ''),
        'certificate' => env('SUNAT_CERTIFICATE_PATH', ''), 'certificate_password' => env('SUNAT_CERTIFICATE_PASSWORD', ''),
        'endpoint' => env('SUNAT_ENDPOINT', 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService'),
        // Código del establecimiento anexo que emite (0000: domicilio fiscal).
        'establishment_code' => env('SUNAT_ESTABLISHMENT_CODE', '0000'),
        // Domicilio fiscal que figura en la representación impresa.
        'address' => env('SUNAT_ADDRESS', ''),
    ],
];
