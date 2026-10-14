<?php

namespace App\Modules\Invoices;

/**
 * Error devuelto por SUNAT como SOAP fault. Los códigos 2000–3999 rechazan el comprobante por su
 * contenido (no se corrige reenviando el mismo XML); el resto son excepciones de servicio,
 * credenciales o formato que pueden reintentarse.
 */
class SunatFault extends \RuntimeException
{
    public function __construct(public readonly string $sunatCode, string $message)
    {
        parent::__construct('SUNAT '.$sunatCode.': '.$message);
    }

    public function rejects(): bool
    {
        $n = (int) $this->sunatCode;

        return ctype_digit($this->sunatCode) && $n >= 2000 && $n < 4000;
    }

    /** Extrae código y mensaje de un SOAP fault; null si la respuesta no lo es. */
    public static function fromSoap(string $body): ?self
    {
        if (! preg_match('#<(?:\w+:)?faultcode>([^<]*)</(?:\w+:)?faultcode>#', $body, $code)) {
            return null;
        }
        preg_match('#<(?:\w+:)?faultstring[^>]*>([^<]*)</(?:\w+:)?faultstring>#', $body, $text);
        // faultcode llega como "soap-env:Client.2335" o solo "0111".
        $value = preg_match('/(\d{4})\s*$/', trim($code[1]), $digits) ? $digits[1] : trim($code[1]);

        return new self($value, html_entity_decode(trim($text[1] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8') ?: 'Error sin descripción');
    }
}
