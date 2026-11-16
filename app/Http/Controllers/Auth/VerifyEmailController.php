<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the email as verified; the Verified event then joins organizations that auto-join its domain.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        $user = $request->user()->fresh();

        return redirect()
            ->route($user->current_organization_id !== null ? 'dashboard' : 'organizations.create')
            ->with('success', $user->current_organization_id !== null
                ? "Email verified. Welcome to {$user->currentOrganization->name}."
                : 'Email verified.');
    }
}
