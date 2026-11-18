<?php

namespace App\Http\Middleware;

use App\Models\RecurringTransaction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lança as recorrências que venceram quando o usuário abre uma tela do app.
 */
class GenerateRecurringTransactions
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            RecurringTransaction::generateDueFor($request->user()->id);
        }

        return $next($request);
    }
}
