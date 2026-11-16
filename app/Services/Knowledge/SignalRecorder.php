<?php

namespace App\Services\Knowledge;

use App\Enums\SignalType;
use App\Jobs\ProposeLearnings;
use App\Models\FindingSignal;
use App\Models\ReviewFinding;

/**
 * Records feedback on findings once per (finding, kind), and asks for new learnings after strong negative feedback.
 */
class SignalRecorder
{
    public function record(ReviewFinding $finding, SignalType $type, ?string $actorLogin = null): FindingSignal
    {
        $signal = FindingSignal::query()->firstOrCreate(
            ['review_finding_id' => $finding->id, 'type' => $type],
            ['repository_id' => $finding->pullRequest->repository_id, 'actor_login' => $actorLogin],
        );

        if ($signal->wasRecentlyCreated && $type->isStrongNegative()) {
            ProposeLearnings::dispatchFor($finding->pullRequest->repository);
        }

        return $signal;
    }
}
