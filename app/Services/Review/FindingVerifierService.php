<?php

namespace App\Services\Review;

use App\Ai\Agents\FindingVerifier;
use App\Enums\AiFeature;
use App\Enums\FindingSeverity;
use App\Enums\FindingVerification;
use App\Models\ReviewRun;
use App\Services\Ai\OrganizationAi;
use App\Services\GitHub\PullRequestClient;
use App\Services\Knowledge\ReviewKnowledge;
use App\Services\Usage\UsageMeter;
use Throwable;

/**
 * Runs the verifier over candidate findings in one call, with real code around each one.
 */
class FindingVerifierService
{
    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public function __construct(private PullRequestClient $client, private UsageMeter $usage, private ModelRouter $models, private OrganizationAi $ai) {}

    /**
     * @param  array<int, ProposedFinding>  $findings  Keyed by an id the verdicts refer back to.
     * @return array<int, Verdict> Missing ids were not verified.
     */
    public function verify(ReviewRun $run, array $findings, ?ReviewKnowledge $knowledge = null): array
    {
        if ($findings === [] || ! config('reviews.verifier.enabled')) {
            return [];
        }

        $organization = $run->pullRequest->repository->organization;
        $model = $this->models->verifierModel($organization, $findings);

        try {
            $response = FindingVerifier::make()->withEffort($organization->ai_effort)->prompt(
                $this->prompt($run, $findings, $knowledge),
                provider: $this->ai->provider($organization),
                model: $model,
                timeout: (int) config('reviews.timeout'),
            );
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $this->inputTokens += $response->usage->inputTokens;
        $this->outputTokens += $response->usage->outputTokens;

        $this->usage->record(
            $organization,
            AiFeature::Verification,
            $organization->aiProvider(),
            $model,
            $response->usage,
            pullRequest: $run->pullRequest,
            reviewRun: $run,
        );

        $verdicts = [];

        foreach ($response['verdicts'] ?? [] as $raw) {
            $id = (int) ($raw['id'] ?? -1);

            if (! array_key_exists($id, $findings) || ! in_array($raw['verdict'] ?? null, ['confirmed', 'rejected'], true)) {
                continue;
            }

            $verdicts[$id] = new Verdict(
                verification: $raw['verdict'] === 'confirmed' ? FindingVerification::Confirmed : FindingVerification::Rejected,
                severity: FindingSeverity::tryFrom((string) ($raw['severity'] ?? '')),
                confidence: isset($raw['confidence']) ? (int) $raw['confidence'] : null,
                reason: trim((string) ($raw['reason'] ?? '')),
                learningId: $this->citedLearning($raw, $knowledge),
            );
        }

        return $verdicts;
    }

    /**
     * @param  array<int, ProposedFinding>  $findings
     */
    /**
     * The learning the verifier says a finding matches, if it is one of those it was shown.
     *
     * @param  array<string, mixed>  $raw
     */
    private function citedLearning(array $raw, ?ReviewKnowledge $knowledge): ?int
    {
        $id = (int) ($raw['learning_id'] ?? 0);

        return $id > 0 && $knowledge?->learnings->contains('id', $id) ? $id : null;
    }

    private function prompt(ReviewRun $run, array $findings, ?ReviewKnowledge $knowledge = null): string
    {
        $contents = [];
        $sections = [];
        $radius = (int) config('reviews.verifier.context_lines');

        foreach ($findings as $id => $finding) {
            $contents[$finding->path] ??= $this->fileContent($run, $finding->path);
            $lines = $contents[$finding->path];

            $from = max(1, $finding->startLine - $radius);
            $to = $lines === null ? $finding->endLine : min(count($lines), $finding->endLine + $radius);
            $excerpt = [];

            for ($line = $from; $line <= $to; $line++) {
                $marker = $line >= $finding->startLine && $line <= $finding->endLine ? '>' : ' ';
                $excerpt[] = str_pad((string) $line, 5, ' ', STR_PAD_LEFT)." {$marker} ".($lines[$line - 1] ?? '');
            }

            $sections[] = implode("\n", [
                "<finding id=\"{$id}\">",
                "File: {$finding->path} lines {$finding->startLine}-{$finding->endLine}",
                "Claimed: {$finding->severity->value} {$finding->category->value}, confidence {$finding->confidence}",
                "Title: {$finding->title}",
                'Explanation: '.$finding->body,
                $finding->graphCallers !== null ? "Code graph: inside {$finding->graphSymbol}, {$finding->graphCallers} caller(s)." : '',
                '<code>',
                $lines === null ? '(file content unavailable)' : implode("\n", $excerpt),
                '</code>',
                '</finding>',
            ]);
        }

        $learnings = $knowledge->learnings ?? collect();
        $preamble = $learnings->isEmpty() ? '' : "<team_learnings>\n".$learnings
            ->map(fn ($learning): string => "- id {$learning->id} ({$learning->kind->value}".($learning->path_glob !== null ? ", {$learning->path_glob}" : '')."): {$learning->body}")
            ->implode("\n")."\n</team_learnings>\n\n";

        return $preamble."Verify these findings. Lines marked \">\" are the ones the finding points at.\n\n".implode("\n\n", $sections);
    }

    /**
     * @return list<string>|null
     */
    private function fileContent(ReviewRun $run, string $path): ?array
    {
        try {
            $content = $this->client->fileContent($run->pullRequest, $path, $run->head_sha);
        } catch (Throwable) {
            return null;
        }

        return $content === null ? null : (preg_split('/\R/', $content) ?: []);
    }
}
