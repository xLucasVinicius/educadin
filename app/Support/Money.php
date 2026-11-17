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
}
