<?php

namespace App\Listeners;

use App\Support\KnownAccounts;
use App\Support\LinkedAccounts;
use Illuminate\Auth\Events\Login;

/** Toda entrada bem-sucedida guarda a conta nas listas deste navegador (sessão e cookie). */
class RememberLinkedAccount
{
    public function handle(Login $event): void
    {
        if ($event->guard === 'web') {
            LinkedAccounts::add((int) $event->user->getAuthIdentifier());
            KnownAccounts::add((int) $event->user->getAuthIdentifier());
        }
    }
}
