<?php

namespace App\Jobs;

use App\Ai\Agents\RepositoryDocumenter;
use App\Enums\AiFeature;
use App\Models\Repository;
use App\Services\Ai\OrganizationAi;
use App\Services\GitHub\PullRequestClient;
use App\Services\Usage\UsageMeter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Generates one current architecture guide per repository after a burst of default-branch merges.
 */
class GenerateRepositoryDocumentation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public Repository $repository)
    {
        $this->onQueue('documentation');
    }

    public static function dispatchFor(Repository $repository, bool $immediate = false): void
    {
        $dispatch = self::dispatch($repository);

        if (! $immediate) {
            $dispatch->delay(now()->addMinutes((int) config('documentation.delay_minutes')));
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    public function uniqueFor(): int
    {
        return ((int) config('documentation.delay_minutes') + 30) * 60;
    }

    public function handle(PullRequestClient $client, UsageMeter $usage, OrganizationAi $ai): void
    {
        $repository = $this->repository->fresh(['organization', 'installation', 'document']);

        if ($repository === null || ! $repository->auto_docs_enabled || $usage->isOverBudget($repository->organization)) {
            return;
        }

        try {
            $sha = $client->branchHead($repository, $repository->default_branch);
            $existing = $repository->document;

            if ($existing?->commit_sha === $sha && filled($existing->content) && $existing->error === null) {
                return;
            }

            $tree = $client->tree($repository, $sha);
            $prompt = $this->prompt($repository, $sha, $tree, $existing?->content, $client);
            $model = $repository->organization->modelFor('review');
            $response = RepositoryDocumenter::make()->withEffort($repository->organization->ai_effort)->prompt(
                $prompt,
                provider: $ai->provider($repository->organization),
                model: $model,
                timeout: (int) config('reviews.timeout'),
            );

            $usage->record(
                $repository->organization,
                AiFeature::Documentation,
                $repository->organization->aiProvider(),
                $model,
                $response->usage,
                $repository,
            );

            $content = trim($response->text);

            if ($content === '') {
                throw new RuntimeException('The documentation model returned an empty document.');
            }

            $repository->document()->updateOrCreate([], [
                'commit_sha' => $sha,
                'content' => $content,
                'generated_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $repository->document()->updateOrCreate([], [
                'error' => Str::limit($exception->getMessage(), 2_000),
            ]);
        }
    }

    /**
     * @param  list<string>  $tree
     */
    private function prompt(Repository $repository, string $sha, array $tree, ?string $previous, PullRequestClient $client): string
    {
        $treeLimit = max(0, (int) config('documentation.max_tree_entries'));
        $treeSection = array_slice($tree, 0, $treeLimit);
        $treePaths = array_fill_keys($tree, true);
        $keyFiles = array_values(array_unique(array_intersect(config('documentation.key_files'), array_keys($treePaths))));
        $sourceExtensions = config('documentation.source_extensions');

        foreach ($tree as $path) {
            $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($extension, $sourceExtensions, true) && ! in_array($path, $keyFiles, true)) {
                $keyFiles[] = $path;
            }
        }

        $fileLimit = max(0, (int) config('documentation.max_files'));
        $sourceBudget = max(0, (int) config('documentation.max_source_characters'));
        $fileCharacterLimit = max(0, (int) config('documentation.max_file_characters'));
        $files = [];

        foreach (array_slice($keyFiles, 0, $fileLimit) as $path) {
            if ($sourceBudget <= 0 || $fileCharacterLimit <= 0) {
                break;
            }

            $content = $client->repositoryFileContent($repository, $path, $sha);

            if ($content === null || trim($content) === '') {
                continue;
            }

            $content = $this->limitText($content, min($fileCharacterLimit, $sourceBudget));
            $files[] = '<file path="'.htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'."\n{$content}\n</file>";
            $sourceBudget -= Str::length($content);
        }

        $sections = [
            "<repository>\nName: {$repository->full_name}\nDefault branch: {$repository->default_branch}\nCommit: {$sha}\n</repository>",
            "<repository_tree>\n".($treeSection === [] ? '(empty)' : implode("\n", $treeSection))."\n</repository_tree>",
        ];

        if (filled($previous)) {
            $sections[] = "<previous_documentation>\n".$this->limitText($previous, $fileCharacterLimit)."\n</previous_documentation>";
        }

        if ($files !== []) {
            $sections[] = "<repository_files>\n".implode("\n\n", $files)."\n</repository_files>";
        }

        return implode("\n\n", $sections);
    }

    private function limitText(string $text, int $limit): string
    {
        if (Str::length($text) <= $limit) {
            return $text;
        }

        $suffix = "\n…(truncated)";

        if ($limit <= Str::length($suffix)) {
            return Str::substr($text, 0, $limit);
        }

        return Str::substr($text, 0, $limit - Str::length($suffix)).$suffix;
    }
}
