<?php

namespace App\Providers;

use App\Repositories\CategoryRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SaleRepository;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
