<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Feriados nacionales del Perú (régimen laboral privado, D. Leg. 713 y leyes posteriores):
 * fechas fijas, Jueves y Viernes Santo, y los días adicionales que se decreten
 * (`commerce.extra_holidays`, fechas Y-m-d separadas por comas). Se usa para contar días hábiles.
 * Verificar la lista cuando una ley cree o traslade un feriado.
 */
class PeruHolidays
{
    /** Mes-día de los feriados de fecha fija. */
    public const FIXED = [
        '01-01', // Año Nuevo
        '05-01', // Día del Trabajo
        '06-07', // Batalla de Arica y Día de la Bandera
        '06-29', // San Pedro y San Pablo
        '07-23', // Día de la Fuerza Aérea del Perú
        '07-28', '07-29', // Fiestas Patrias
        '08-06', // Batalla de Junín
        '08-30', // Santa Rosa de Lima
        '10-08', // Combate de Angamos
        '11-01', // Todos los Santos
        '12-08', // Inmaculada Concepción
        '12-09', // Batalla de Ayacucho
        '12-25', // Navidad
    ];

    public static function isHoliday(CarbonInterface $day): bool
    {
        if (in_array($day->format('m-d'), self::FIXED, true)) {
            return true;
        }
        $easter = self::easter($day->year);
        $date = $day->format('Y-m-d');
        // Jueves y Viernes Santo.
        if ($date === $easter->modify('-3 days')->format('Y-m-d') || $date === $easter->modify('-2 days')->format('Y-m-d')) {
            return true;
        }

        return in_array($date, self::extra(), true);
    }

    public static function isBusinessDay(CarbonInterface $day): bool
    {
        return ! $day->isWeekend() && ! self::isHoliday($day);
    }

    /** Domingo de Pascua (algoritmo gregoriano anónimo); no depende de la extensión calendar. */
    public static function easter(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** @return list<string> */
    private static function extra(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('commerce.extra_holidays', '')))));
    }
}
