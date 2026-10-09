<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::viaRequest('station-token', function (Request $request): ?User {
            $token = $request->bearerToken();

            if ($token === null) {
                return null;
            }

            return User::query()
                ->where('api_token', hash('sha256', $token))
                ->where('status', 'Active')
                ->first();
        });

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::transliterate(
                Str::lower($request->string('username')->toString()).'|'.$request->ip(),
            ));
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perHour(10)->by($request->ip());
        });
    }
}
