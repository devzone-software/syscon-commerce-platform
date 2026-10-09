<?php

return [
    'jwt_secret' => env('JWT_SECRET', ''), 'service_token' => env('SERVICE_TOKEN', ''),
    'supplier_hosts' => array_filter(array_map('trim', explode(',', strtolower(env('SUPPLIER_ALLOWED_HOSTS', ''))))),
    'admin_email' => env('ADMIN_EMAIL'), 'admin_password' => env('ADMIN_PASSWORD'),
    'sunat' => [
        'ruc' => env('SUNAT_RUC', ''), 'legal_name' => env('SUNAT_LEGAL_NAME', 'SYSCON'),
        'user' => env('SUNAT_SOL_USER', ''), 'password' => env('SUNAT_SOL_PASSWORD', ''),
        'certificate' => env('SUNAT_CERTIFICATE_PATH', ''), 'certificate_password' => env('SUNAT_CERTIFICATE_PASSWORD', ''),
        'endpoint' => env('SUNAT_ENDPOINT', 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService'),
    ],
];
