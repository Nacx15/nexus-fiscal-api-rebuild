<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Application\Company\CompanyContext;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            CompanyContext::class,
            fn () => new CompanyContext()
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
