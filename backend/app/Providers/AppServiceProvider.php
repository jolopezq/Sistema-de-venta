<?php

namespace App\Providers;

use App\Repositories\CategoryRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SaleRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Se registran los Repositorios en el Service Container de Laravel
     * para permitir inyección de dependencias correcta sin instanciación
     * manual (evitar `new Repository()` — buenas-practicas.md §4).
     *
     * Aunque Laravel los resuelve por auto-binding, registrarlos aquí
     * de forma explícita permite:
     * - Reemplazarlos fácilmente por mocks en tests.
     * - Vincularlos a contratos/interfaces en el futuro sin tocar los consumidores.
     */
    public function register(): void
    {
        $this->app->bind(ProductRepository::class);
        $this->app->bind(CategoryRepository::class);
        $this->app->bind(SaleRepository::class);
        $this->app->bind(CustomerRepository::class);
        $this->app->scoped(\App\Services\PermissionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Límite de intentos de login por email + IP (todas las cajas suelen compartir IP).
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by(strtolower((string) $request->input('email')) . '|' . $request->ip());
        });

        // Recuperar contraseña: pocos correos por dirección e IP, y pocos intentos de código por IP y por correo.
        $tooMany = fn () => response()->json(['message' => 'Demasiados intentos. Espera unos minutos y vuelve a intentar.'], 429);
        RateLimiter::for('password-code', function (Request $request) use ($tooMany) {
            $email = strtolower(trim((string) $request->input('email')));
            return [
                Limit::perMinutes(15, 3)->by('code|' . $email . '|' . $request->ip())->response($tooMany),
                Limit::perHour(10)->by('code-ip|' . $request->ip())->response($tooMany),
            ];
        });
        RateLimiter::for('password-verify', function (Request $request) use ($tooMany) {
            $email = strtolower(trim((string) $request->input('email')));
            return [
                Limit::perMinute(10)->by('verify-ip|' . $request->ip())->response($tooMany),
                Limit::perMinutes(15, 15)->by('verify|' . $email)->response($tooMany),
            ];
        });

        // Envío por la API HTTPS de Resend sin paquetes nuevos (MAIL_MAILER=resend-api)
        \Illuminate\Support\Facades\Mail::extend('resend-api', fn (array $config) => new \App\Mail\Transport\ResendApiTransport((string) ($config['key'] ?? config('services.resend.key'))));
    }
}
