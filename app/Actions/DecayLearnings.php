<?php

namespace App\Actions;

use App\Enums\LearningStatus;
use App\Models\Learning;

/**
 * Active learnings that have not matched anything for a while lose weight, and are archived once they
 * fall below the floor. Run daily.
 */
class DecayLearnings
{
    /**
     * @return array{decayed: int, archived: int}
     */
    public function handle(): array
    {
        $idleSince = now()->subDays((int) config('learning.decay.idle_days'));
        $factor = (float) config('learning.decay.daily_factor');
        $floor = (float) config('learning.decay.archive_below');

        $decayed = 0;
        $archived = 0;

        Learning::query()
            ->where('status', LearningStatus::Active)
            ->where(fn ($query) => $query
                ->where('last_applied_at', '<', $idleSince)
                ->orWhere(fn ($query) => $query->whereNull('last_applied_at')->where('reviewed_at', '<', $idleSince)))
            ->chunkById(200, function ($learnings) use ($factor, $floor, &$decayed, &$archived): void {
                foreach ($learnings as $learning) {
                    $weight = round($learning->weight * $factor, 4);
                    $learning->forceFill([
                        'weight' => $weight,
                        'status' => $weight < $floor ? LearningStatus::Archived : LearningStatus::Active,
                    ])->saveQuietly();

                    $decayed++;
                    $archived += $weight < $floor ? 1 : 0;
                }
            });

        return ['decayed' => $decayed, 'archived' => $archived];
    }
}
