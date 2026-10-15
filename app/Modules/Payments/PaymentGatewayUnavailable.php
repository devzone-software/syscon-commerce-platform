<?php

namespace App\Modules\Payments;

/** La pasarela no respondió o respondió algo inesperado; el pago queda pendiente y se puede reintentar. */
class PaymentGatewayUnavailable extends \RuntimeException {}
