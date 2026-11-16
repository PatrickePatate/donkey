<?php

namespace App\Providers;

use App\Actions\AutoJoinOrganizations;
use App\Models\User;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubUserAuthorization;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GitHubApp::class, fn (): GitHubApp => new GitHubApp(config('services.github')));
        $this->app->singleton(GitHubUserAuthorization::class, fn (): GitHubUserAuthorization => new GitHubUserAuthorization(config('services.github')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Verified users join organizations that auto-join their email domain, when they verify and on every
        // sign-in (so a domain added later still picks them up).
        Event::listen(Verified::class, fn (Verified $event) => $event->user instanceof User ? app(AutoJoinOrganizations::class)->handle($event->user) : null);
        Event::listen(Login::class, fn (Login $event) => $event->user instanceof User ? app(AutoJoinOrganizations::class)->handle($event->user) : null);
    }
}
