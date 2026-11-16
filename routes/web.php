<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\GitHub\InstallController;
use App\Http\Controllers\GitHub\SetupController;
use App\Http\Controllers\GitHub\WebhookController;
use App\Http\Controllers\SwitchOrganizationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::post('/webhooks/github', WebhookController::class)
    ->middleware('github.webhook')
    ->name('webhooks.github');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'pages::auth.login')->name('login');
    Route::livewire('/register', 'pages::auth.register')->name('register');
});

Route::livewire('/invitations/{token}', 'pages::invitations.accept')->name('invitations.accept');

Route::middleware('auth')->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::livewire('/organizations/create', 'pages::organizations.create')->name('organizations.create');
    Route::put('/organizations/{organization}/switch', SwitchOrganizationController::class)->name('organizations.switch');

    Route::middleware('organization')->group(function () {
        Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard');
        Route::livewire('/repositories', 'pages::repositories.index')->name('repositories.index');
        Route::livewire('/pull-requests', 'pages::pull-requests.index')->name('pull-requests.index');
        Route::livewire('/pull-requests/{pullRequest}', 'pages::pull-requests.show')->name('pull-requests.show');
        Route::livewire('/agents', 'pages::agents.index')->name('agents.index');
        Route::livewire('/agents/create', 'pages::agents.edit')->name('agents.create');
        Route::livewire('/agents/{agent}/edit', 'pages::agents.edit')->name('agents.edit');
        Route::livewire('/settings/organization', 'pages::settings.organization')->name('settings.organization');
        Route::livewire('/settings/members', 'pages::settings.members')->name('settings.members');

        Route::get('/github/install', InstallController::class)->name('github.install');
        Route::get('/github/setup', SetupController::class)->name('github.setup');
    });
});
