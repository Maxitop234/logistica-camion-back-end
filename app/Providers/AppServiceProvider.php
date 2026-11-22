<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        // Un sensor nuevo prueba el código de su etiqueta: se limita por IP y por hardware.
        RateLimiter::for('iot-vinculacion', fn (Request $request): array => [
            Limit::perMinute((int) config('iot.vinculacion.intentos_por_ip', 10))->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('iot.vinculacion.intentos_por_hardware', 20))
                ->by('hw:'.mb_strtolower((string) $request->input('codigo_hardware'))),
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Con APP_URL https (Render/AWS) todas las URLs generadas salen en https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceHttps();
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
