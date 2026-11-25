<?php

namespace App\Providers;

use App\Contracts\BankingGatewayInterface;
use App\Services\Banking\MockEpayBankingGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BankingGatewayInterface::class, function () {
            return new MockEpayBankingGateway;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
