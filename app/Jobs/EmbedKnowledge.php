<?php

namespace App\Jobs;

use App\Models\Learning;
use App\Models\RepositoryMemory;
use App\Services\Knowledge\Embedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stores the embedding of a memory or learning so reviews can find it by similarity.
 */
class EmbedKnowledge implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public RepositoryMemory|Learning $model)
    {
        $this->onQueue('default');
    }

    public function handle(Embedder $embedder): void
    {
        $model = $this->model->fresh();

        if ($model === null) {
            return;
        }

        $embedding = $embedder->embed($model->body);

        if ($embedding !== null) {
            $model->forceFill(['embedding' => $embedding])->saveQuietly();
        }
    }

    /**
     * Queue an embedding when embeddings are on and the text is new or changed.
     */
    public static function whenNeeded(RepositoryMemory|Learning $model): void
    {
        if (filled(config('learning.embeddings.provider')) && ($model->wasRecentlyCreated || $model->wasChanged('body'))) {
            self::dispatch($model)->afterCommit();
        }
    }
}
