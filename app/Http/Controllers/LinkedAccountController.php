<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\KnownAccounts;
use App\Support\LinkedAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LinkedAccountController
{
    /** Troca para outra conta já usada neste navegador. */
    public function switch(Request $request, User $user): RedirectResponse
    {
        abort_unless(LinkedAccounts::has($user->id), 404);

        if ($user->isSuspended()) {
            LinkedAccounts::remove($user->id);

            return redirect()->route('dashboard');
        }

        $this->signInAs($request, $user);

        return redirect()->route('dashboard');
    }

    /** Tira uma conta da lista de contas conhecidas deste navegador. */
    public function forget(User $user): RedirectResponse
    {
        abort_unless(KnownAccounts::has($user->id), 404);

        KnownAccounts::remove($user->id);
        LinkedAccounts::remove($user->id);

        return redirect()->route('login');
    }

    /** Sai da conta atual (mantendo a lista) e abre o login para entrar em outra. */
    public function add(Request $request): RedirectResponse
    {
        LinkedAccounts::add(Auth::id());

        Auth::guard('web')->logout();
        $request->session()->regenerate();

        return redirect()->route('login');
    }

    /** Sai só da conta atual: vai para outra conta ligada ou, se não houver, encerra tudo. */
    public function leave(Request $request): RedirectResponse
    {
        LinkedAccounts::remove(Auth::id());
        $next = LinkedAccounts::users()->first();

        if ($next) {
            $this->signInAs($request, $next);

            return redirect()->route('dashboard');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function signInAs(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
    }
}
