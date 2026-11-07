<?php

namespace UrbanoDev\Midia;

use Illuminate\Support\ServiceProvider;

class MidiaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/midia.php', 'midia');
    }

    public function boot(): void
    {
        // Banco por tenant (stancl): desligue e publique em database/migrations/tenant.
        if (config('midia.carregar_migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->publishes([__DIR__.'/../config/midia.php' => config_path('midia.php')], 'midia-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'midia-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Instalar::class,
                Console\Conferir::class,
                Console\Faxinar::class,
                Console\Restaurar::class,
            ]);
        }
    }
}
