<?php

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessGitHubWebhook;
use App\Jobs\SyncInstallationRepositories;
use App\Models\GithubInstallation;
use App\Models\Repository;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

function postWebhook(string $event, array $payload, ?string $deliveryId = null, ?string $signature = null): TestResponse
{
    $body = json_encode($payload);

    return test()->call('POST', route('webhooks.github'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_GITHUB_DELIVERY' => $deliveryId ?? fake()->uuid(),
        'HTTP_X_HUB_SIGNATURE_256' => $signature ?? githubSignature($body),
    ], content: $body);
}

it('rejects deliveries with an invalid signature', function () {
    Queue::fake();

    postWebhook('ping', ['zen' => 'hi'], signature: 'sha256=nope')->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('stores a delivery and queues it for processing', function () {
    Queue::fake();

    postWebhook('installation_repositories', ['action' => 'added', 'installation' => ['id' => 42]], 'delivery-1')
        ->assertAccepted();

    $event = WebhookEvent::sole();

    expect($event)
        ->delivery_id->toBe('delivery-1')
        ->event->toBe('installation_repositories')
        ->action->toBe('added')
        ->installation_id->toBe(42);

    Queue::assertPushedOn('webhooks', ProcessGitHubWebhook::class);
});

it('ignores redelivered events', function () {
    Queue::fake();

    postWebhook('ping', ['zen' => 'hi'], 'same-delivery')->assertAccepted();
    postWebhook('ping', ['zen' => 'hi'], 'same-delivery')->assertOk()->assertJson(['status' => 'duplicate']);

    expect(WebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessGitHubWebhook::class, 1);
});

it('adds and removes repositories for a known installation', function () {
    $installation = GithubInstallation::factory()->create();
    $removed = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $installation->organization_id]);

    $event = WebhookEvent::factory()->create([
        'event' => 'installation_repositories',
        'action' => 'added',
        'installation_id' => $installation->installation_id,
        'payload' => [
            'repository_selection' => 'selected',
            'repositories_added' => [['id' => 555, 'full_name' => 'acme/api', 'private' => true]],
            'repositories_removed' => [['id' => $removed->github_id, 'full_name' => $removed->full_name]],
        ],
    ]);

    (new ProcessGitHubWebhook($event))->handle();

    expect($installation->repositories()->pluck('full_name')->all())->toBe(['acme/api'])
        ->and($event->fresh()->status)->toBe(WebhookEventStatus::Processed);
});

it('removes the installation and its repositories when the app is uninstalled', function () {
    $installation = GithubInstallation::factory()->create();
    Repository::factory()->for($installation, 'installation')->create(['organization_id' => $installation->organization_id]);

    $event = WebhookEvent::factory()->create([
        'event' => 'installation',
        'action' => 'deleted',
        'installation_id' => $installation->installation_id,
    ]);

    (new ProcessGitHubWebhook($event))->handle();

    expect(GithubInstallation::count())->toBe(0)
        ->and(Repository::count())->toBe(0);
});

it('marks installations as suspended', function () {
    $installation = GithubInstallation::factory()->create();

    $event = WebhookEvent::factory()->create([
        'event' => 'installation',
        'action' => 'suspend',
        'installation_id' => $installation->installation_id,
    ]);

    (new ProcessGitHubWebhook($event))->handle();

    expect($installation->fresh()->isSuspended())->toBeTrue();
});

it('resyncs repositories when an installation accepts new permissions', function () {
    Bus::fake([SyncInstallationRepositories::class]);
    $installation = GithubInstallation::factory()->create();

    $event = WebhookEvent::factory()->create([
        'event' => 'installation',
        'action' => 'new_permissions_accepted',
        'installation_id' => $installation->installation_id,
    ]);

    (new ProcessGitHubWebhook($event))->handle();

    Bus::assertDispatched(SyncInstallationRepositories::class);
});

it('ignores events for installations it does not know', function () {
    $event = WebhookEvent::factory()->create([
        'event' => 'installation_repositories',
        'action' => 'added',
        'installation_id' => 999,
        'payload' => ['repositories_added' => [['id' => 1, 'full_name' => 'x/y']]],
    ]);

    (new ProcessGitHubWebhook($event))->handle();

    expect($event->fresh()->status)->toBe(WebhookEventStatus::Ignored)
        ->and(Repository::count())->toBe(0);
});
