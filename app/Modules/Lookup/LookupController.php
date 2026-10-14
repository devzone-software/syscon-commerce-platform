<?php

namespace App\Modules\Lookup;

use App\Support\Api;

class LookupController
{
    public function __construct(private RucLookup $lookup) {}

    /** Razón social, estado y condición de un RUC; 404 si el proveedor no lo encuentra. */
    public function ruc(string $ruc)
    {
        try {
            $result = $this->lookup->find($ruc);
        } catch (LookupUnavailable) {
            abort(503, 'La consulta de RUC no respondió; ingresa la razón social.');
        }
        abort_unless($result['found'], 404, 'SUNAT no registra ese RUC.');

        return Api::ok('RUC', $result);
    }
}
