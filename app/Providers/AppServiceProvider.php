<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\WhatsAppGateway;
use App\Services\Payments\MidtransGateway;
use App\Services\WhatsApp\HttpWhatsAppGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsAppGateway::class, fn(): HttpWhatsAppGateway => new HttpWhatsAppGateway(
            (array) config('services.whatsapp', []),
        ));

        $this->app->bind(PaymentGateway::class, fn(): MidtransGateway => new MidtransGateway(
            (array) config('services.midtrans', []),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
