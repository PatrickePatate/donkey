<?php

namespace App\Support;

use App\Services\GitHub\GitHubApp;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * Failures caused by load on GitHub or the AI provider, worth retrying the whole job later rather than failing it.
 */
final class TransientFailure
{
    /**
     * Seconds to wait before retrying, or null when the failure is not about load.
     */
    public static function backoff(Throwable $exception, int $attempt): ?int
    {
        $isTransient = $exception instanceof RateLimitedException
            || $exception instanceof ProviderOverloadedException
            || ($exception instanceof RequestException && (
                GitHubApp::isRateLimited($exception->response)
                || in_array($exception->response->status(), [502, 503, 504], true)
            ));

        if (! $isTransient) {
            return null;
        }

        $retryAfter = $exception instanceof RequestException ? (int) $exception->response->header('Retry-After') : 0;

        return max($retryAfter, min(900, 30 * 2 ** max(0, $attempt - 1)));
    }
}
