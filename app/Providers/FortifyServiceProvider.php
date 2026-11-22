<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Fortify se usa solo como biblioteca (TOTP y códigos de recuperación del
     * usuario). Sus rutas web (registro, recuperación, login, 2FA) no se
     * registran: la autenticación es la API con tokens de Sanctum.
     */
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        //
    }
}
