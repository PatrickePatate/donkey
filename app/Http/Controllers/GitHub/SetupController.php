<?php

namespace App\Http\Controllers\GitHub;

use App\Http\Controllers\Controller;
use App\Jobs\SyncInstallationRepositories;
use App\Models\GithubInstallation;
use App\Models\Organization;
use App\Services\Audit\AuditLog;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubUserAuthorization;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

/**
 * GitHub's "Setup URL": links the installation to the organization that started the flow.
 */
class SetupController extends Controller
{
    public function __invoke(Request $request, GitHubApp $github, GitHubUserAuthorization $userAuthorization, AuditLog $audit): RedirectResponse
    {
        $validated = $request->validate([
            'installation_id' => ['required', 'integer'],
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $state = $this->decodeState($validated['state']);
        $expectedNonce = $request->session()->pull('github_install_nonce');

        abort_if(
            $state === null
                || $expectedNonce === null
                || ! hash_equals($expectedNonce, (string) $state['nonce'])
                || $state['user_id'] !== $request->user()->id
                || $state['expires_at'] < now()->timestamp,
            403,
            'This installation link is invalid or has expired. Start again from Repositories.',
        );

        $organization = Organization::query()->findOrFail($state['organization_id']);
        Gate::authorize('manageGithub', $organization);

        $installationId = (int) $validated['installation_id'];
        $userToken = $userAuthorization->exchangeCode($validated['code']);

        abort_unless(
            $userToken !== null && $userAuthorization->userCanAccessInstallation($userToken, $installationId),
            403,
            'Your GitHub account does not have access to this installation.',
        );

        $existing = GithubInstallation::query()->firstWhere('installation_id', $installationId);

        abort_if(
            $existing !== null && $existing->organization_id !== $organization->id,
            409,
            'This GitHub installation is already linked to another organization.',
        );

        $remote = $github->installation($installationId);

        $installation = GithubInstallation::query()->updateOrCreate(
            ['installation_id' => $installationId],
            [
                'organization_id' => $organization->id,
                'account_id' => $remote['account']['id'],
                'account_login' => $remote['account']['login'],
                'account_type' => $remote['account']['type'],
                'repository_selection' => $remote['repository_selection'] ?? 'selected',
                'suspended_at' => $remote['suspended_at'] ?? null,
            ],
        );

        SyncInstallationRepositories::dispatch($installation);
        $audit->record($organization, 'github.connected', $installation, ['account' => $installation->account_login]);

        return redirect()->route('repositories.index')
            ->with('success', "Connected {$installation->account_login}. Repositories are syncing…");
    }

    /**
     * @return array{organization_id: int, user_id: int, nonce: string, expires_at: int}|null
     */
    private function decodeState(string $state): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
