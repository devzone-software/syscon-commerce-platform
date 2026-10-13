<?php

namespace App\Modules\Orders;

use Illuminate\Validation\Validator;

/**
 * Datos de facturación que el cliente elige al comprar y que el pedido conserva como copia.
 * Los códigos siguen los catálogos SUNAT: 01 factura / 03 boleta (catálogo 01) y
 * 1 DNI, 4 carné de extranjería, 6 RUC, 7 pasaporte (catálogo 06).
 */
class BillingData
{
    public const INVOICE_TYPES = ['01', '03'];

    public const DOCUMENT_TYPES = ['1', '4', '6', '7'];

    /** Reglas de Laravel para un bloque de facturación anidado bajo $prefix (p. ej. "billing."). */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'type' => ['required', 'in:'.implode(',', self::INVOICE_TYPES)],
            $prefix.'documentType' => ['required', 'in:'.implode(',', self::DOCUMENT_TYPES)],
            $prefix.'documentNumber' => ['required', 'string', 'max:15'],
            $prefix.'name' => ['required', 'string', 'min:2', 'max:255'],
            $prefix.'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Reglas que dependen de varios campos: formato del documento y factura solo con RUC. */
    public static function check(Validator $validator, array $data, string $prefix = ''): void
    {
        $type = $data['type'] ?? null;
        $documentType = $data['documentType'] ?? null;
        $number = (string) ($data['documentNumber'] ?? '');
        if ($type === '01' && $documentType !== '6') {
            $validator->errors()->add($prefix.'documentType', 'La factura requiere el RUC del cliente.');
        }
        if ($number !== '' && ($error = self::documentError((string) $documentType, $number))) {
            $validator->errors()->add($prefix.'documentNumber', $error);
        }
    }

    public static function documentError(string $documentType, string $number): ?string
    {
        return match ($documentType) {
            '1' => preg_match('/^\d{8}$/', $number) ? null : 'El DNI tiene 8 dígitos.',
            '6' => self::validRuc($number) ? null : 'El RUC no es válido.',
            '4', '7' => preg_match('/^[A-Z0-9]{6,12}$/', $number) ? null : 'El documento tiene de 6 a 12 letras o números.',
            default => null,
        };
    }

    /** RUC de 11 dígitos con prefijo 10, 15, 17 o 20 y dígito verificador módulo 11. */
    public static function validRuc(string $ruc): bool
    {
        if (! preg_match('/^(10|15|17|20)\d{9}$/', $ruc)) {
            return false;
        }
        $sum = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $weight) {
            $sum += (int) $ruc[$i] * $weight;
        }
        $digit = (11 - $sum % 11) % 10;

        return $digit === (int) $ruc[10];
    }

    /** Normaliza lo recibido antes de validar: sin espacios y documento en mayúsculas. */
    public static function normalize(array $data): array
    {
        foreach (['documentNumber', 'name', 'address'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = preg_replace('/\s+/u', ' ', trim($data[$key]));
            }
        }
        if (isset($data['documentNumber']) && is_string($data['documentNumber'])) {
            $data['documentNumber'] = strtoupper(str_replace(' ', '', $data['documentNumber']));
        }
        if (array_key_exists('address', $data) && $data['address'] === '') {
            $data['address'] = null;
        }

        return $data;
    }
}
