<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the GitHub App: app-level (JWT) and installation-level requests.
 */
class GitHubApp
{
    /**
     * @param  array{app_id: ?string, app_slug: ?string, private_key_path: ?string, webhook_secret: ?string, api_url: string, web_url: string}  $config
     */
    public function __construct(private array $config) {}

    public function slug(): string
    {
        return (string) $this->config['app_slug'];
    }

    public function isConfigured(): bool
    {
        return filled($this->config['app_id']) && filled($this->config['app_slug']) && filled($this->config['private_key_path']);
    }

    /**
     * URL that starts the installation flow on GitHub, carrying our signed state.
     */
    public function installUrl(string $state): string
    {
        return rtrim($this->config['web_url'], '/').'/apps/'.$this->slug().'/installations/new?'.http_build_query(['state' => $state]);
    }

    /**
     * Fetch an installation using the app JWT.
     *
     * @return array<string, mixed>
     */
    public function installation(int $installationId): array
    {
        return $this->asApp()->get("/app/installations/{$installationId}")->throw()->json();
    }

    /**
     * Every repository the installation can access.
     *
     * @return list<array<string, mixed>>
     */
    public function installationRepositories(int $installationId): array
    {
        $repositories = [];
        $page = 1;

        do {
            $response = $this->asInstallation($installationId)
                ->get('/installation/repositories', ['per_page' => 100, 'page' => $page])
                ->throw()
                ->json();

            $repositories = [...$repositories, ...$response['repositories']];
            $page++;
        } while (count($repositories) < $response['total_count'] && $response['repositories'] !== []);

        return $repositories;
    }

    public function asApp(): PendingRequest
    {
        return $this->baseRequest()->withToken($this->jwt());
    }

    public function asInstallation(int $installationId): PendingRequest
    {
        return $this->baseRequest()->withToken($this->installationToken($installationId));
    }

    /**
     * Installation access tokens last one hour; cache them for slightly less.
     */
    public function installationToken(int $installationId): string
    {
        return Cache::remember(
            "github:installation-token:{$installationId}",
            now()->addMinutes(50),
            fn (): string => $this->asApp()
                ->post("/app/installations/{$installationId}/access_tokens")
                ->throw()
                ->json('token'),
        );
    }

    public function forgetInstallationToken(int $installationId): void
    {
        Cache::forget("github:installation-token:{$installationId}");
    }

    /**
     * RS256-signed JWT identifying the app, valid for under ten minutes as GitHub requires.
     */
    public function jwt(): string
    {
        $now = time();

        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode(json_encode([
                'iat' => $now - 60,
                'exp' => $now + 540,
                'iss' => (string) $this->config['app_id'],
            ])),
        ];

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the GitHub App JWT.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl($this->config['api_url'])
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20)
            ->retry(2, 500, throw: false);
    }

    private function privateKey(): string
    {
        $path = (string) $this->config['private_key_path'];
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_readable($path)) {
            throw new RuntimeException("GitHub App private key not readable at [{$path}].");
        }

        return (string) file_get_contents($path);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
