<?php

namespace App\Jobs;

use App\Models\Repository;
use App\Services\GitHub\PullRequestClient;
use App\Services\Security\PackagistAdvisories;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads the composer.lock of the monitored branch (the default one unless set) and records the Packagist advisories affecting it.
 */
class ScanRepositoryDependencies implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public Repository $repository)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    public function handle(PullRequestClient $client, PackagistAdvisories $packagist): void
    {
        $repository = $this->repository;

        try {
            $sha = $client->branchHead($repository, $repository->securityBranch());
            $lock = $client->repositoryFileContent($repository, 'composer.lock', $sha);
            $packages = $lock === null ? [] : $packagist->lockedPackages($lock);
            $advisories = $packages === [] ? [] : $packagist->affecting($packages);
        } catch (Throwable $exception) {
            report($exception);
            // Keep the previous results: a failed scan says nothing about the dependencies.
            $repository->update(['dependencies_scanned_at' => now(), 'dependencies_scan_error' => mb_substr($exception->getMessage(), 0, 255)]);

            return;
        }

        DB::transaction(function () use ($repository, $sha, $lock, $packages, $advisories): void {
            $repository->securityAdvisories()->delete();
            $repository->securityAdvisories()->createMany($advisories);

            $repository->update([
                'dependencies_scanned_at' => now(),
                'dependencies_commit_sha' => $sha,
                'composer_packages_count' => $lock === null ? null : count($packages),
                'dependencies_scan_error' => null,
            ]);
        });
    }
}
