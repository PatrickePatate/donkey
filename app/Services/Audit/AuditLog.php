<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;

/**
 * Records who changed what in an organization: settings, members, agents, learnings, budgets.
 */
class AuditLog
{
    /**
     * @param  string  $action  Dotted name, e.g. "learning.approved".
     * @param  array<string, mixed>  $metadata  Changed values; never secrets.
     */
    public function record(Organization $organization, string $action, ?Model $subject = null, array $metadata = [], ?string $actor = null): AuditEvent
    {
        $user = auth()->user();

        return $organization->auditEvents()->create([
            'user_id' => $user?->getKey(),
            'actor' => $actor ?? $user->name ?? 'System',
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * Only the attributes that actually changed on a model that was just saved, as [old, new].
     *
     * @param  list<string>  $only
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function changes(Model $model, array $only = []): array
    {
        $changes = [];
        $previousValues = $model->getPrevious();
        $previous = $model->newInstance()->setRawAttributes($previousValues);

        foreach (array_keys($model->getChanges()) as $key) {
            if ($key === 'updated_at' || ($only !== [] && ! in_array($key, $only, true))) {
                continue;
            }

            $changes[$key] = [
                array_key_exists($key, $previousValues) ? $previous->getAttribute($key) : null,
                $model->getAttribute($key),
            ];
        }

        return $changes;
    }
}
