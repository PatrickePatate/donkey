<?php

namespace App\Services\GitHub;

use Illuminate\Support\Facades\Http;

/**
 * Exchanges the OAuth code GitHub sends on setup, and checks which installations that user can access.
 */
class GitHubUserAuthorization
{
    /**
     * @param  array{client_id: ?string, client_secret: ?string, api_url: string, web_url: string}  $config
     */
    public function __construct(private array $config) {}

    public function exchangeCode(string $code): ?string
    {
        $response = Http::acceptJson()
            ->asForm()
            ->post(rtrim($this->config['web_url'], '/').'/login/oauth/access_token', [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'code' => $code,
            ]);

        return $response->successful() ? $response->json('access_token') : null;
    }

    public function userCanAccessInstallation(string $userToken, int $installationId): bool
    {
        $page = 1;

        do {
            $response = Http::baseUrl($this->config['api_url'])
                ->acceptJson()
                ->withToken($userToken)
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->get('/user/installations', ['per_page' => 100, 'page' => $page]);

            if ($response->failed()) {
                return false;
            }

            $installations = $response->json('installations', []);

            if (in_array($installationId, array_column($installations, 'id'), true)) {
                return true;
            }

            $page++;
        } while (count($installations) === 100);

        return false;
    }
}
