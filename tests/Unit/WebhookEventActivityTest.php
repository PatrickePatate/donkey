<?php

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;

function activityOf(string $event, ?string $action, array $payload = [], WebhookEventStatus $status = WebhookEventStatus::Processed): array
{
    return (new WebhookEvent(['event' => $event, 'action' => $action, 'payload' => ['repository' => ['full_name' => 'acme/app'], ...$payload], 'status' => $status]))->activity();
}

it('tells merges from plain closes', function () {
    expect(activityOf('pull_request', 'closed', ['pull_request' => ['number' => 9, 'merged' => true]]))
        ->toMatchArray(['tone' => 'success', 'title' => 'Merged', 'subject' => 'acme/app#9'])
        ->and(activityOf('pull_request', 'closed', ['pull_request' => ['number' => 9, 'merged' => false]]))
        ->toMatchArray(['tone' => 'neutral', 'title' => 'Closed']);
});

it('flags failed deliveries whatever their event', function () {
    expect(activityOf('pull_request', 'opened', ['pull_request' => ['number' => 3]], WebhookEventStatus::Failed))
        ->toMatchArray(['tone' => 'danger', 'title' => 'Webhook failed', 'subject' => 'acme/app#3']);
});

it('falls back to a readable name for unknown events', function () {
    expect(activityOf('check_suite', 'completed'))->toMatchArray(['tone' => 'neutral', 'title' => 'Check suite completed', 'subject' => 'acme/app']);
});
