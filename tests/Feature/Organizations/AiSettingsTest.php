<?php

use App\Ai\Agents\CodeReviewer;
use App\Enums\OrganizationRole;
use App\Enums\ReviewRunStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Services\Ai\OrganizationAi;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiManager;
use Livewire\Livewire;

require_once __DIR__.'/../Reviews/ReviewTestHelpers.php';

it('lets owners choose the provider, key and models, and stores the key encrypted', function () {
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::settings.organization')
        ->set('aiProvider', 'openrouter')
        ->set('aiApiKey', 'sk-or-secret')
        ->set('reviewModel', 'vendor/big')
        ->set('ecoModel', 'vendor/small')
        ->call('saveAi')
        ->assertHasNoErrors()
        ->assertSet('aiApiKey', '');

    $organization->refresh();

    expect($organization->aiProvider())->toBe('openrouter')
        ->and($organization->ai_api_key)->toBe('sk-or-secret')
        ->and(DB::table('organizations')->where('id', $organization->id)->value('ai_api_key'))->not->toContain('sk-or-secret')
        ->and($organization->toArray())->not->toHaveKey('ai_api_key')
        ->and(AuditEvent::sole()->metadata)->not->toContain('sk-or-secret');

    Livewire::actingAs($owner)
        ->test('pages::settings.organization')
        ->set('reviewModel', 'vendor/other')
        ->call('saveAi');

    expect($organization->fresh()->ai_api_key)->toBe('sk-or-secret')
        ->and($organization->fresh()->review_model)->toBe('vendor/other');

    Livewire::actingAs($owner)->test('pages::settings.organization')->call('removeAiKey');

    expect($organization->fresh()->hasAiKey())->toBeFalse();
});

it('keeps the AI provider settings away from admins', function () {
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);
    [$admin] = userInOrganization();
    $organization->addMember($admin, OrganizationRole::Admin);
    $admin->switchOrganization($organization);
    $admin = $admin->fresh();

    $this->actingAs($admin)->get(route('settings.organization'))->assertOk()->assertDontSee('AI provider');
    Livewire::actingAs($admin)->test('pages::settings.organization')->call('saveAi')->assertForbidden();
});

it('falls back from a role model to the review model to the built-in default', function () {
    $organization = Organization::factory()->make(['review_model' => null, 'eco_model' => null, 'verifier_model' => 'vendor/verify']);

    expect($organization->modelFor('review'))->toBe(config('reviews.model'))
        ->and($organization->modelFor('eco'))->toBe(config('reviews.eco.model'));

    $organization->review_model = 'vendor/main';

    expect($organization->modelFor('eco'))->toBe('vendor/main')
        ->and($organization->modelFor('conversation'))->toBe('vendor/main')
        ->and($organization->modelFor('verifier'))->toBe('vendor/verify');
});

it('builds a provider with the organization key', function () {
    $organization = Organization::factory()->create(['ai_provider' => 'openrouter', 'ai_api_key' => 'sk-or-mine']);

    $name = app(OrganizationAi::class)->provider($organization);
    $provider = app(AiManager::class)->textProvider($name);

    expect($provider->driver())->toBe('openrouter')
        ->and($provider->providerCredentials()['key'])->toBe('sk-or-mine');
});

it('reviews with the organization provider and model', function () {
    setUpReviewFixture($this);
    $this->repository->organization->update(['ai_provider' => 'openrouter', 'review_model' => 'vendor/big']);
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([])]);

    runReview($this->run);

    expect($this->run->fresh())->provider->toBe('openrouter')->model->toBe('vendor/big');
    CodeReviewer::assertPrompted(fn ($prompt) => $prompt->model === 'vendor/big');
});

it('skips reviews until the organization has an API key', function () {
    setUpReviewFixture($this);
    $this->repository->organization->update(['ai_api_key' => null]);
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([])]);

    runReview($this->run);

    expect($this->run->fresh())->status->toBe(ReviewRunStatus::Skipped)->summary->toContain('no AI provider');
    CodeReviewer::assertNeverPrompted();
});
