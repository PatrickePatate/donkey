<?php

namespace App\Http\Controllers\GitHub;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGitHubWebhook;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stores each delivery once (keyed on X-GitHub-Delivery) and hands it to the queue.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $deliveryId = (string) $request->header('X-GitHub-Delivery');
        $event = (string) $request->header('X-GitHub-Event');

        abort_if($deliveryId === '' || $event === '', 400, 'Missing GitHub delivery headers.');

        $payload = $request->json()->all();

        $webhookEvent = WebhookEvent::query()->firstOrCreate(
            ['delivery_id' => $deliveryId],
            [
                'event' => $event,
                'action' => $payload['action'] ?? null,
                'installation_id' => $payload['installation']['id'] ?? null,
                'payload' => $payload,
            ],
        );

        if (! $webhookEvent->wasRecentlyCreated) {
            return response()->json(['status' => 'duplicate']);
        }

        ProcessGitHubWebhook::dispatch($webhookEvent);

        return response()->json(['status' => 'accepted'], 202);
    }
}
