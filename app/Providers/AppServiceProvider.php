<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Regra de senha única do sistema (cadastro, redefinição e troca de senha).
        // A checagem de vazamentos consulta um serviço externo, então só roda em produção.
        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()
            : Password::min(8)->mixedCase()->letters()->numbers()->symbols(),
        );
    }
}
