<?php

namespace App\Services\Review;

use App\Ai\Agents\CodeReviewer;
use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Models\PullRequest;
use App\Models\ReviewAgent;
use App\Services\Graph\GraphContext;

/**
 * Runs the reviewer over each chunk of the diff and keeps only findings that point at real diff lines.
 */
class ReviewAnalyzer
{
    /**
     * @param  ?ReviewAgent  $agent  A specialist agent to run instead of the general reviewer.
     */
    public function analyze(PullRequest $pullRequest, ReviewContext $context, ?GraphContext $graph = null, ?ReviewAgent $agent = null): ReviewAnalysis
    {
        $findings = [];
        $summaries = [];
        $riskScore = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $discarded = 0;

        foreach ($context->chunks((int) config('reviews.chunk_characters')) as $chunk) {
            $response = CodeReviewer::make(agent: $agent)->prompt(
                $this->prompt($pullRequest, $context, $chunk, $graph),
                provider: config('reviews.provider'),
                model: filled($agent?->model) ? $agent->model : config('reviews.model'),
                timeout: (int) config('reviews.timeout'),
            );

            $inputTokens += $response->usage->inputTokens;
            $outputTokens += $response->usage->outputTokens;
            $riskScore = max($riskScore, (int) ($response['risk_score'] ?? 0));

            if (filled($response['summary'] ?? null)) {
                $summaries[] = trim((string) $response['summary']);
            }

            foreach ($response['findings'] ?? [] as $raw) {
                $finding = $this->validate($context, is_array($raw) ? $raw : []);

                if ($finding === null || ($agent !== null && ! $this->isWithinAgentScope($agent, $finding))) {
                    $discarded++;

                    continue;
                }

                $finding = $finding->fromAgent($agent?->id);

                if ($graph !== null) {
                    $function = $graph->functionAt($finding->path, $finding->startLine);
                    $finding = $finding->withImpact($function?->qualifiedName, $function?->callers, $graph->impactBoost($function));
                }

                $findings[$finding->fingerprint()] ??= $finding;
            }
        }

        return new ReviewAnalysis(array_values($findings), $summaries, min(100, $riskScore), $inputTokens, $outputTokens, $discarded);
    }

    /**
     * Agents only report on their own files, categories and severities.
     */
    private function isWithinAgentScope(ReviewAgent $agent, ProposedFinding $finding): bool
    {
        return $agent->matchesPath($finding->path)
            && in_array($finding->category, $agent->allowedCategories(), true)
            && $agent->allowsSeverity($finding->severity);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function validate(ReviewContext $context, array $raw): ?ProposedFinding
    {
        $file = $context->file((string) ($raw['file'] ?? ''));
        $category = FindingCategory::tryFrom((string) ($raw['category'] ?? ''));
        $severity = FindingSeverity::tryFrom((string) ($raw['severity'] ?? ''));
        $title = trim((string) ($raw['title'] ?? ''));

        if ($file === null || $category === null || $severity === null || $title === '') {
            return null;
        }

        $startLine = (int) ($raw['start_line'] ?? 0);
        $endLine = max($startLine, (int) ($raw['end_line'] ?? $startLine));

        if (! $file->canCommentRange($startLine, $endLine)) {
            if (! $file->canComment($startLine)) {
                return null;
            }

            // Keep the finding on its first line rather than dropping it; a suggestion would no longer fit.
            $endLine = $startLine;
            $raw['suggestion'] = '';
        }

        $suggestion = (string) ($raw['suggestion'] ?? '');

        return new ProposedFinding(
            path: $file->path,
            startLine: $startLine,
            endLine: $endLine,
            category: $category,
            severity: $severity,
            confidence: max(0, min(100, (int) ($raw['confidence'] ?? 0))),
            title: mb_substr($title, 0, 255),
            body: trim((string) ($raw['explanation'] ?? '')),
            suggestion: trim($suggestion) === '' ? null : rtrim($suggestion, "\n"),
            code: $file->codeBetween($startLine, $endLine),
        );
    }

    /**
     * @param  list<FilePatch>  $chunk
     */
    private function prompt(PullRequest $pullRequest, ReviewContext $context, array $chunk, ?GraphContext $graph): string
    {
        $sections = [];

        $sections[] = implode("\n", [
            '<pull_request>',
            'Title: '.$pullRequest->title,
            'Branch: '.$pullRequest->head_ref.' → '.$pullRequest->base_ref,
            'Description:',
            trim((string) $pullRequest->body) !== '' ? trim((string) $pullRequest->body) : '(none)',
            '</pull_request>',
        ]);

        if ($context->instructions !== []) {
            $sections[] = '<repository_instructions>';

            foreach ($context->instructions as $path => $content) {
                $sections[] = "<instructions path=\"{$path}\">\n{$content}\n</instructions>";
            }

            $sections[] = '</repository_instructions>';
        }

        if ($graph !== null && $graph->prompt !== '') {
            $sections[] = implode("\n", [
                '<code_graph>',
                'Structural analysis of the whole repository (deterministic, from the code graph): changed functions, their',
                'callers, contract changes, blast radius and test coverage. Use it to judge impact beyond the diff, e.g. callers',
                'that still assume an old signature. Absence from the graph is not proof of absence in the code.',
                '',
                $graph->prompt,
                '</code_graph>',
            ]);
        }

        if ($context->isIncremental) {
            $sections[] = 'These files changed since your previous review of this pull request. Focus on the newly added lines.';
        }

        $sections[] = '<diff>';

        foreach ($chunk as $file) {
            $sections[] = "<file path=\"{$file->path}\" status=\"{$file->status}\">\n{$file->annotated()}\n</file>";
        }

        $sections[] = '</diff>';

        return implode("\n\n", $sections);
    }
}
