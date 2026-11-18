<?php

namespace App\Support;

use Illuminate\Support\Number;

class Money
{
    /**
     * Formata centavos como reais (ex.: 123456 => "R$ 1.234,56").
     */
    public static function format(int $cents): string
    {
        return Number::currency($cents / 100, 'BRL', 'pt_BR');
    }

    /**
     * Formato curto para eixos de gráfico (ex.: 150000 => "R$ 1,5 mil").
     */
    public static function compact(int $cents): string
    {
        $abs = abs($cents) / 100;

        // Até 2 casas nos números pequenos (1,05 mil), sem zeros sobrando.
        $short = function (float $value): string {
            $text = number_format($value, $value < 10 ? 2 : ($value < 100 ? 1 : 0), ',', '.');

            return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
        };

        $text = match (true) {
            $abs >= 1_000_000 => $short($abs / 1_000_000).' mi',
            $abs >= 1_000 => $short($abs / 1_000).' mil',
            default => number_format($abs, 0, ',', '.'),
        };

        return ($cents < 0 ? '-' : '').'R$ '.$text;
    }

    /**
     * Valores "redondos" (em centavos) que cobrem o intervalo, para o eixo de
     * um gráfico: de 3 a 6 marcas igualmente espaçadas, da menor para a maior.
     *
     * @return array<int, int>
     */
    public static function niceTicks(int $min, int $max, int $target = 4): array
    {
        // Saldo (quase) constante: abre uma faixa de R$ 100 em volta para o eixo ter marcas distintas.
        if ($max - $min < 10000) {
            $middle = intdiv($min + $max, 2);
            $min = $middle - 5000;
            $max = $middle + 5000;
        }

        $range = $max - $min;
        $raw = $range / $target;
        $magnitude = 10 ** floor(log10($raw));
        $normalized = $raw / $magnitude;
        $step = (int) max(100, ($normalized <= 1 ? 1 : ($normalized <= 2 ? 2 : ($normalized <= 5 ? 5 : 10))) * $magnitude);

        $start = (int) (floor($min / $step) * $step);
        $end = (int) (ceil($max / $step) * $step);

        if ($start === $end) {
            $end += $step;
        }

        $ticks = [];
        for ($value = $start; $value <= $end; $value += $step) {
            $ticks[] = $value;
        }

        return $ticks;
    }
}
