<?php

namespace App\Http\Controllers\GitHub;

use App\Http\Controllers\Controller;
use App\Services\GitHub\GitHubApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Sends an admin to GitHub to install the app, carrying a state tied to their session.
 */
class InstallController extends Controller
{
    public function __invoke(Request $request, GitHubApp $github): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;

        Gate::authorize('manageGithub', $organization);

        if (! $github->isConfigured()) {
            return redirect()->route('repositories.index')->with('error', 'The GitHub App is not configured yet. Set the GITHUB_APP_* variables.');
        }

        $nonce = Str::random(40);
        $request->session()->put('github_install_nonce', $nonce);

        $state = Crypt::encryptString(json_encode([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'nonce' => $nonce,
            'expires_at' => now()->addMinutes(30)->timestamp,
        ]));

        return redirect()->away($github->installUrl($state));
    }
}
