<?php

namespace App\Enums;

enum TransactionCategory: string
{
    // Receitas
    case Salary = 'salary';
    case Returns = 'returns';
    case Gift = 'gift';
    case OtherIncome = 'other_income';

    // Despesas
    case Food = 'food';
    case Housing = 'housing';
    case Transport = 'transport';
    case Health = 'health';
    case Education = 'education';
    case Leisure = 'leisure';
    case Shopping = 'shopping';
    case Bills = 'bills';
    case OtherExpense = 'other_expense';

    public function label(): string
    {
        return match ($this) {
            self::Salary => __('Salary'),
            self::Returns => __('Investment returns'),
            self::Gift => __('Gift'),
            self::OtherIncome => __('Other income'),
            self::Food => __('Food'),
            self::Housing => __('Housing'),
            self::Transport => __('Transport'),
            self::Health => __('Health'),
            self::Education => __('Education'),
            self::Leisure => __('Leisure'),
            self::Shopping => __('Shopping'),
            self::Bills => __('Bills and services'),
            self::OtherExpense => __('Other expenses'),
        };
    }

    public function type(): TransactionType
    {
        return match ($this) {
            self::Salary, self::Returns, self::Gift, self::OtherIncome => TransactionType::Income,
            default => TransactionType::Expense,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function forType(TransactionType $type): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => $c->type() === $type));
    }
}
