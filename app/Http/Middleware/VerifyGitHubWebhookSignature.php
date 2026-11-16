<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhook deliveries whose X-Hub-Signature-256 does not match our secret.
 */
class VerifyGitHubWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.github.webhook_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');

        abort_if($secret === '', Response::HTTP_SERVICE_UNAVAILABLE, 'GitHub webhook secret is not configured.');

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        abort_unless(hash_equals($expected, $signature), Response::HTTP_UNAUTHORIZED, 'Invalid signature.');

        return $next($request);
    }
}
