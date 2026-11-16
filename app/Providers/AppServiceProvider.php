<?php

namespace App\Providers;

use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubUserAuthorization;
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
        //
    }
}
