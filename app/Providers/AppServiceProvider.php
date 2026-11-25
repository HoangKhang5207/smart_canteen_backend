<?php

namespace App\Providers;

use App\Contracts\BankingGatewayInterface;
use App\Contracts\SmsGatewayInterface;
use App\Enums\OtpPurpose;
use App\Exceptions\SmsDeliveryException;
use App\Services\Banking\MockEpayBankingGateway;
use App\Services\Sms\EsmsSmsGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\TwilioSmsGateway;
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

        $this->app->bind(SmsGatewayInterface::class, function ($app) {
            $driver = strtolower(trim((string) config('services.sms.driver', 'log')));

            return match ($driver) {
                'log' => $app->make(LogSmsGateway::class),
                'esms' => $app->make(EsmsSmsGateway::class),
                'twilio' => $app->make(TwilioSmsGateway::class),
                default => new class($driver) implements SmsGatewayInterface
                {
                    public function __construct(private readonly string $driver) {}

                    public function sendOtp(string $phoneNumber, string $otpCode, OtpPurpose $purpose, int $ttlSeconds): array
                    {
                        throw new SmsDeliveryException(
                            sprintf('Cấu hình SMS_DRIVER [%s] không hợp lệ.', $this->driver),
                        );
                    }
                },
            };
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
