<?php

namespace App\Services\Review;

use App\Models\ReviewRun;
use App\Services\GitHub\PullRequestClient;
use Illuminate\Support\Str;

class ReviewContextBuilder
{
    private const MAX_INSTRUCTION_DIRECTORIES = 12;

    public function __construct(private PullRequestClient $client) {}

    public function build(ReviewRun $run): ReviewContext
    {
        $pullRequest = $run->pullRequest;
        $prFiles = $this->client->files($pullRequest);

        $baseSha = $pullRequest->base_sha;
        $changedSinceLastReview = null;

        if (! $run->is_full && $pullRequest->last_reviewed_sha !== null && $pullRequest->last_reviewed_sha !== $run->head_sha) {
            $comparison = $this->client->compare($pullRequest, $pullRequest->last_reviewed_sha, $run->head_sha);

            if ($comparison['status'] === 'ahead') {
                $baseSha = $pullRequest->last_reviewed_sha;
                $changedSinceLastReview = array_column($comparison['files'], 'filename');
            }
        }

        $files = [];
        $skipped = [];

        foreach ($prFiles as $file) {
            $path = (string) $file['filename'];

            if ($changedSinceLastReview !== null && ! in_array($path, $changedSinceLastReview, true)) {
                continue;
            }

            if (
                ! isset($file['patch'])
                || ($file['status'] ?? null) === 'removed'
                || $this->isIgnored($path)
                || strlen((string) $file['patch']) > config('reviews.max_patch_characters')
                || count($files) >= config('reviews.max_files')
            ) {
                $skipped[] = $path;

                continue;
            }

            // Always parse the PR-level patch: inline comments must target lines of the PR diff.
            $files[$path] = FilePatch::fromGitHub($path, (string) $file['status'], (string) $file['patch']);
        }

        return new ReviewContext(
            baseSha: $baseSha,
            isIncremental: $changedSinceLastReview !== null,
            files: $files,
            instructions: $files === [] ? [] : $this->instructions($run, array_keys($files)),
            skippedFiles: $skipped,
        );
    }

    /**
     * CLAUDE.md / AGENTS.md from the root and from the directories of changed files, closest last.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function instructions(ReviewRun $run, array $paths): array
    {
        $directories = collect($paths)
            ->flatMap(function (string $path): array {
                $segments = array_slice(explode('/', $path), 0, -1);
                $ancestors = [''];

                foreach ($segments as $index => $segment) {
                    $ancestors[] = implode('/', array_slice($segments, 0, $index + 1));
                }

                return $ancestors;
            })
            ->unique()
            ->sortBy(fn (string $directory): int => substr_count($directory, '/') + ($directory === '' ? 0 : 1))
            ->take(self::MAX_INSTRUCTION_DIRECTORIES);

        $instructions = [];
        $budget = (int) config('reviews.max_instruction_characters');

        foreach ($directories as $directory) {
            foreach (config('reviews.instruction_files') as $fileName) {
                $path = ltrim($directory.'/'.$fileName, '/');
                $content = $this->client->fileContent($run->pullRequest, $path, $run->head_sha);

                if ($content === null || trim($content) === '' || $budget <= 0) {
                    continue;
                }

                $instructions[$path] = Str::limit($content, $budget, "\n…(truncated)");
                $budget -= strlen($instructions[$path]);
            }
        }

        return $instructions;
    }

    private function isIgnored(string $path): bool
    {
        foreach (config('reviews.ignored_paths') as $pattern) {
            if (Str::is($pattern, $path) || Str::is($pattern, basename($path))) {
                return true;
            }
        }

        return false;
    }
}
