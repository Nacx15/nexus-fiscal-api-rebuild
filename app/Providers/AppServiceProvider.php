<?php

namespace App\Providers;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Contracts\PacAccessTokenResolver;
use App\Domain\Fiscal\Contracts\PacStampingGateway;
use App\Infrastructure\Pac\SwSapien\SwSapienStampingGateway;
use App\Infrastructure\Pac\UnconfiguredPacAccessTokenResolver;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            CompanyContext::class,
            fn () => new CompanyContext
        );

        $this->app->bind(
            PacStampingGateway::class,
            SwSapienStampingGateway::class
        );

        $this->app->bind(
            PacAccessTokenResolver::class,
            UnconfiguredPacAccessTokenResolver::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'invoice' => Invoice::class,
            'user' => User::class,
        ]);
    }
}
