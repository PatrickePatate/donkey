<?php

use App\Mcp\Servers\DonkeyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

Mcp::oauthRoutes(registration: false);

// Dynamic client registration is public by spec and creates a DB row per call, so only it is throttled.
Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware('throttle:10,1')
    ->name('mcp.oauth.register');

Mcp::web('/mcp', DonkeyServer::class)->middleware('auth:api');
