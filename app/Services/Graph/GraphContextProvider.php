<?php

namespace App\Services\Graph;

use App\Enums\RunGraphStatus;
use App\Models\RepositoryGraph;
use App\Models\ReviewRun;
use App\Services\Review\ReviewContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Produces kodus-graph context for a review: base graph at the PR base, changed files at the PR head.
 * Never fails a review; when anything goes wrong the run continues without graph context.
 */
class GraphContextProvider
{
    public function __construct(
        private KodusGraph $kodusGraph,
        private RepositoryWorkspace $workspace,
        private GraphBuilder $builder,
        private GraphExplorer $explorer,
    ) {}

    public function forReview(ReviewRun $run, ReviewContext $context): GraphContextResult
    {
        if (! config('graph.enabled')) {
            return new GraphContextResult(RunGraphStatus::Disabled);
        }

        if (! $this->kodusGraph->isAvailable()) {
            return new GraphContextResult(RunGraphStatus::Unavailable, error: 'kodus-graph is not installed on this worker.');
        }

        $pullRequest = $run->pullRequest;
        $repository = $pullRequest->repository;
        $scratch = storage_path('app/private/tmp/graph-'.Str::uuid());
        File::ensureDirectoryExists($scratch);

        try {
            return $this->workspace->locked($repository, function (RepositoryWorkspace $workspace) use ($run, $context, $repository, $pullRequest, $scratch): GraphContextResult {
                $graph = $this->builder->ensure($workspace, $repository, $pullRequest->base_sha);
                $directory = $workspace->checkout($repository, $run->head_sha);

                // Deleted files are absent from the head checkout, which is how kodus-graph sees their symbols as removed.
                $files = [...array_keys($context->files), ...$context->removedFiles];
                $diffPath = $scratch.'/pr.diff';
                File::put($diffPath, $this->unifiedDiff($context));

                $promptPath = $scratch.'/context.xml';
                $this->kodusGraph->context($directory, (string) $graph->path, $files, $diffPath, 'xml', $promptPath);

                $jsonPath = $scratch.'/context.json';
                $this->kodusGraph->context($directory, (string) $graph->path, $files, $diffPath, 'json', $jsonPath);

                return new GraphContextResult(
                    RunGraphStatus::Ready,
                    $this->toContext($this->withoutNoise((string) File::get($promptPath)), $jsonPath, $graph, $context),
                    baseGraph: $graph,
                );
            });
        } catch (Throwable $exception) {
            report($exception);

            return new GraphContextResult(RunGraphStatus::Unavailable, error: mb_substr($exception->getMessage(), 0, 500));
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * Drop the file-to-file imports (they repeat the diff's own use lines and fill ~40% of the block) and kodus's comments.
     */
    private function withoutNoise(string $xml): string
    {
        return trim((string) preg_replace(['#<!--.*?-->\s*#s', '#<Imports\b(?:[^>]*/>|.*?</Imports>)\s*#s'], '', $xml));
    }

    private function toContext(string $xml, string $jsonPath, RepositoryGraph $graph, ReviewContext $context): GraphContext
    {
        $analysis = [];

        if (is_file($jsonPath) && filesize($jsonPath) <= (int) config('graph.max_json_bytes')) {
            $decoded = json_decode((string) File::get($jsonPath), true);
            $analysis = is_array($decoded) ? ($decoded['analysis'] ?? []) : [];
        }

        $risk = $analysis['risk'] ?? [];
        $removed = $this->removedSymbols($graph, (array) ($analysis['structural_diff']['nodes']['removed'] ?? []), $context);

        return new GraphContext(
            prompt: Str::limit(trim($removed."\n\n".$xml), (int) config('graph.max_prompt_characters'), "\n…(truncated)"),
            riskLevel: isset($risk['level']) ? strtoupper((string) $risk['level']) : null,
            riskScore: isset($risk['score']) ? (int) round(((float) $risk['score']) * 100) : null,
            changedFunctions: array_values(array_map(
                fn (array $function): ChangedFunction => ChangedFunction::fromKodusGraph($function),
                array_filter($analysis['changed_functions'] ?? [], 'is_array'),
            )),
            testGaps: count($analysis['test_gaps'] ?? []),
            impactedFunctions: (int) ($analysis['blast_radius']['total_functions'] ?? 0),
            impactedFiles: (int) ($analysis['blast_radius']['total_files'] ?? 0),
            affectedFlows: count($analysis['affected_flows'] ?? []),
        );
    }

    /**
     * Symbols this change deletes that base-branch code still uses: kodus-graph detects them but its XML leaves them out.
     *
     * @param  array<int, mixed>  $removed  structural_diff.nodes.removed from kodus-graph's JSON analysis.
     */
    private function removedSymbols(RepositoryGraph $graph, array $removed, ReviewContext $context): string
    {
        $entries = [];

        foreach ($removed as $node) {
            $id = is_array($node) ? (string) ($node['qualified_name'] ?? '') : '';
            $users = array_filter(
                $id === '' ? [] : ($this->explorer->neighbourhood($graph, $id, 10)['callers'] ?? []),
                fn (array $caller): bool => ! in_array($caller['node']['file'], $context->removedFiles, true),
            );

            if ($users === []) {
                continue;
            }

            $lines = array_map(fn (array $caller): string => sprintf(
                '    <StillUsedBy name="%s" file="%s" line="%d" via="%s"%s />',
                e(Str::after($caller['node']['id'], '::')),
                e($caller['node']['file']),
                $caller['node']['line'],
                e($caller['kind']),
                isset($context->files[$caller['node']['file']]) ? ' fileChangedInPullRequest="true"' : '',
            ), $users);

            $entries[] = sprintf('  <Removed name="%s" file="%s">', e(Str::after($id, '::')), e((string) ($node['file_path'] ?? '')))
                ."\n".implode("\n", $lines)."\n  </Removed>";

            if (count($entries) >= 15) {
                break;
            }
        }

        return $entries === [] ? '' : "<RemovedSymbols>\n".implode("\n", $entries)."\n</RemovedSymbols>";
    }

    /**
     * Rebuild a git-style unified diff from GitHub's per-file patches.
     */
    private function unifiedDiff(ReviewContext $context): string
    {
        $parts = [];

        foreach ($context->files as $file) {
            $parts[] = implode("\n", [
                "diff --git a/{$file->path} b/{$file->path}",
                $file->status === 'added' ? '--- /dev/null' : "--- a/{$file->path}",
                "+++ b/{$file->path}",
                rtrim($file->patch, "\n"),
            ]);
        }

        return implode("\n", $parts)."\n";
    }
}
