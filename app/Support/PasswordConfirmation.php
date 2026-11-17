<?php

namespace App\Support;

/**
 * Confirmação recente de senha (mesma chave de sessão do middleware
 * "password.confirm" do Laravel, que as rotas do Fortify também usam).
 */
class PasswordConfirmation
{
    /** Tempo, em segundos, em que a senha confirmada vale para ações sensíveis. */
    public const WINDOW = 300;

    public static function fresh(): bool
    {
        $window = min(self::WINDOW, (int) config('auth.password_timeout', 10800));

        return (time() - (int) session('auth.password_confirmed_at', 0)) < $window;
    }

    public static function confirm(): void
    {
        session(['auth.password_confirmed_at' => time()]);
    }
}
