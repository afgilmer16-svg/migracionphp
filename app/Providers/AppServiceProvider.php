<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Escale\EscaleClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EscaleClient::class, fn (): EscaleClient => new EscaleClient(
            baseUrl: (string) config('services.escale.url'),
            retries: (int) config('services.escale.retries'),
            retryDelayMs: (int) config('services.escale.retry_delay_ms'),
            timeout: (int) config('services.escale.timeout'),
        ));
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
    }
}
