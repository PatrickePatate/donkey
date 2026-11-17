<?php

use App\Mcp\Servers\DonkeyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Dynamic client registration is public by spec and creates a DB row per call.
Route::middleware('throttle:10,1')->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', DonkeyServer::class)->middleware('auth:api');
