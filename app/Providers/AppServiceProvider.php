<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Models\Project;
use App\Models\User;
use App\Observers\ProjectObserver;
use App\Observers\UserObserver;
use App\Support\AccountAccess;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

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
        Auth::provider('active-eloquent', fn ($app, array $config) => new ActiveUserProvider($app['hash'], $config['model']));
        Gate::before(fn (User $user) => AccountAccess::allows($user) ? null : false);
        Event::listen(Login::class, function (Login $event): void {
            $current = User::query()->find($event->user->getAuthIdentifier());
            if (! $current || ! $current->isActive() || (int) $event->user->access_version !== $current->access_version) {
                Auth::guard($event->guard)->logoutCurrentDevice();
                session()->invalidate();
                session()->regenerateToken();
                throw ValidationException::withMessages(['email' => AccountAccess::UNAVAILABLE]);
            }
            session()->put(AccountAccess::SESSION_KEY, ['id' => $current->id, 'version' => $current->access_version]);
        });
        User::observe(UserObserver::class);
        Project::observe(ProjectObserver::class);
    }
}
