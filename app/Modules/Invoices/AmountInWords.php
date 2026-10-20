<?php

namespace App\Modules\Invoices;

/**
 * Importe en letras de la leyenda 1000 (catálogo 52), p. ej. "MIL OCHOCIENTOS CINCUENTA Y OCHO
 * CON 59/100 SOLES". Cubre hasta 999 999 999,99.
 */
final class AmountInWords
{
    private const UNITS = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE',
        'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS',
        'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];

    private const TENS = [3 => 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const HUNDREDS = [1 => 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function soles(string $amount): string
    {
        [$integer, $cents] = explode('.', bcadd($amount, '0', 2));

        return self::number((int) $integer).' CON '.$cents.'/100 SOLES';
    }

    private static function number(int $n): string
    {
        if ($n === 0) {
            return 'CERO';
        }
        $millions = intdiv($n, 1000000);
        $thousands = intdiv($n % 1000000, 1000);
        $parts = [];
        if ($millions) {
            $parts[] = $millions === 1 ? 'UN MILLÓN' : self::apocope(self::hundreds($millions)).' MILLONES';
        }
        if ($thousands) {
            $parts[] = $thousands === 1 ? 'MIL' : self::apocope(self::hundreds($thousands)).' MIL';
        }
        if ($n % 1000) {
            $parts[] = self::hundreds($n % 1000);
        }

        return implode(' ', $parts);
    }

    /** De 1 a 999. */
    private static function hundreds(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }
        $rest = $n % 100;
        $words = $n >= 100 ? [self::HUNDREDS[intdiv($n, 100)]] : [];
        if ($rest >= 30) {
            $words[] = self::TENS[intdiv($rest, 10)].($rest % 10 ? ' Y '.self::UNITS[$rest % 10] : '');
        } elseif ($rest > 0) {
            $words[] = self::UNITS[$rest];
        }

        return implode(' ', $words);
    }

    /** "UNO" se apocopa ante MIL y MILLONES: VEINTIÚN MIL, TREINTA Y UN MILLONES. */
    private static function apocope(string $words): string
    {
        return preg_replace(['/VEINTIUNO$/u', '/UNO$/u'], ['VEINTIÚN', 'UN'], $words);
    }
}
