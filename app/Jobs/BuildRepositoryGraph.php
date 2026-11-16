<?php

namespace App\Jobs;

use App\Models\Repository;
use App\Services\Graph\GraphBuilder;
use App\Services\Graph\KodusGraph;
use App\Services\Graph\RepositoryWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pre-builds a base graph so reviews of the next pull requests don't pay for a full parse.
 */
class BuildRepositoryGraph implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public Repository $repository, public string $commitSha)
    {
        $this->onQueue('graphs');
    }

    public function uniqueId(): string
    {
        return "{$this->repository->id}:{$this->commitSha}";
    }

    public function handle(KodusGraph $kodusGraph, RepositoryWorkspace $workspace, GraphBuilder $builder): void
    {
        if (! $kodusGraph->isAvailable()) {
            return;
        }

        try {
            $workspace->locked($this->repository, fn (RepositoryWorkspace $workspace) => $builder->ensure($workspace, $this->repository, $this->commitSha));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
