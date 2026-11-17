<?php

namespace App\Enums;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case Salary = 'salary';
    case Digital = 'digital';

    public function label(): string
    {
        return match ($this) {
            self::Checking => __('Checking account'),
            self::Savings => __('Savings account'),
            self::Salary => __('Salary account'),
            self::Digital => __('Digital account'),
        };
    }
}
