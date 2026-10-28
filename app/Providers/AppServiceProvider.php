<?php

namespace App\Providers;

use App\Notifications\Push\FcmPushNotifier;
use App\Notifications\Push\LogPushNotifier;
use App\Notifications\Push\PushNotifier;
use App\Services\TodayOverview;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Dashboard memanggil ringkasan hari ini dari beberapa widget sekaligus.
        $this->app->scoped(TodayOverview::class);

        $this->app->singleton(PushNotifier::class, function () {
            $path = config('services.fcm.credentials');

            return $path && is_file($path) ? new FcmPushNotifier($path) : new LogPushNotifier;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('login')).'|'.$r->ip()),
            Limit::perMinute(30)->by($r->ip()),
        ]);
        RateLimiter::for('attendance', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
    }
}
