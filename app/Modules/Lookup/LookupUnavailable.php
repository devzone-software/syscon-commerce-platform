<?php

namespace App\Modules\Lookup;

/** El proveedor de consulta no respondió; la compra continúa con los datos que ingresó el cliente. */
class LookupUnavailable extends \RuntimeException {}
