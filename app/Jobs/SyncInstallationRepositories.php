<?php

namespace App\Jobs;

use App\Events\RepositoriesSynced;
use App\Models\GithubInstallation;
use App\Services\GitHub\GitHubApp;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Mirrors the repositories an installation can access: upserts new ones, removes revoked ones,
 * and queues a graph build of each default branch (a no-op when that commit is already built).
 */
class SyncInstallationRepositories implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public GithubInstallation $installation)
    {
        $this->onQueue('github');
    }

    public function uniqueId(): string
    {
        return (string) $this->installation->installation_id;
    }

    public function handle(GitHubApp $github): void
    {
        $repositories = $github->installationRepositories($this->installation->installation_id);

        foreach ($repositories as $repository) {
            $synced = $this->installation->repositories()->updateOrCreate(
                ['github_id' => $repository['id']],
                [
                    'organization_id' => $this->installation->organization_id,
                    ...self::attributesFrom($repository),
                ],
            );

            BuildRepositoryGraph::dispatch($synced);
        }

        $this->installation->repositories()
            ->whereNotIn('github_id', array_column($repositories, 'id'))
            ->delete();

        $this->installation->forceFill(['synced_at' => now()])->save();

        RepositoriesSynced::dispatch($this->installation->organization_id);
    }

    /**
     * @param  array<string, mixed>  $repository
     * @return array{full_name: string, default_branch: string, is_private: bool}
     */
    public static function attributesFrom(array $repository): array
    {
        return [
            'full_name' => $repository['full_name'],
            'default_branch' => $repository['default_branch'] ?? 'main',
            'is_private' => (bool) ($repository['private'] ?? true),
        ];
    }
}
