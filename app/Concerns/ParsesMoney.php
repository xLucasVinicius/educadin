<?php

namespace App\Concerns;

trait ParsesMoney
{
    /**
     * Regex de um valor em reais digitado no formato brasileiro (ex.: 1.234,56).
     */
    protected function moneyPattern(bool $allowNegative = true): string
    {
        return '/^'.($allowNegative ? '-?' : '').'(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$/';
    }

    /**
     * Converte "1.234,56" ou "-50,00" em centavos (inteiro).
     */
    protected function parseAmountToCents(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $value = str_replace('.', '', ltrim($value, '-'));

        [$reais, $centavos] = array_pad(explode(',', $value, 2), 2, '0');
        $centavos = str_pad($centavos, 2, '0');

        $cents = ((int) $reais * 100) + (int) $centavos;

        return $negative ? -$cents : $cents;
    }
}
