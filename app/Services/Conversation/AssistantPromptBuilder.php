<?php

namespace App\Services\Conversation;

use App\Enums\ConversationRole;
use App\Enums\FindingStatus;
use App\Enums\ReviewRunStatus;
use App\Models\ConversationMessage;
use App\Models\ReviewFinding;
use App\Services\GitHub\PullRequestClient;
use App\Services\Review\FilePatch;
use Illuminate\Support\Str;

/**
 * Gathers what the assistant needs to answer a question: the pull request, its latest review,
 * the finding and thread being discussed, earlier exchanges and the relevant diff.
 */
class AssistantPromptBuilder
{
    public function __construct(private PullRequestClient $client) {}

    public function build(ConversationMessage $question): string
    {
        $pullRequest = $question->pullRequest;
        $thread = $question->thread_comment_id !== null ? $this->client->reviewThread($pullRequest, $question->thread_comment_id) : [];
        $finding = $question->review_finding_id !== null ? $question->finding : null;
        $threadPath = $finding !== null ? $finding->file_path : ($thread[0]['path'] ?? null);

        $sections = [
            $this->pullRequestSection($question),
            $this->reviewSection($question),
            $finding !== null ? $this->findingSection($finding) : null,
            $thread !== [] ? $this->threadSection($thread) : $this->historySection($question),
            $this->diffSection($question, is_string($threadPath) ? $threadPath : null),
            "<question author=\"{$question->author_login}\">\n{$question->body}\n</question>",
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function pullRequestSection(ConversationMessage $question): string
    {
        $pullRequest = $question->pullRequest;

        return implode("\n", [
            '<pull_request>',
            "Repository: {$pullRequest->repository->full_name}",
            "#{$pullRequest->number} {$pullRequest->title} (by {$pullRequest->author_login}, {$pullRequest->state})",
            "Branch: {$pullRequest->head_ref} → {$pullRequest->base_ref}",
            'Description:',
            trim((string) $pullRequest->body) !== '' ? Str::limit(trim((string) $pullRequest->body), 4000) : '(none)',
            '</pull_request>',
        ]);
    }

    private function reviewSection(ConversationMessage $question): ?string
    {
        $run = $question->pullRequest->reviewRuns()
            ->where('status', ReviewRunStatus::Completed)
            ->where('is_dry_run', false)
            ->whereNull('review_agent_id')
            ->latest('id')
            ->first();

        $findings = $question->pullRequest->findings()
            ->whereIn('status', [FindingStatus::Published, FindingStatus::Dismissed])
            ->whereHas('reviewRun', fn ($query) => $query->where('is_dry_run', false))
            ->with('agent')
            ->orderByDesc('risk_score')
            ->limit((int) config('reviews.conversation.max_findings'))
            ->get();

        if ($run === null && $findings->isEmpty()) {
            return null;
        }

        $lines = ['<review>'];

        if (filled($run?->summary)) {
            $lines[] = "Summary of your latest review (risk {$run->risk_score}/100):";
            $lines[] = trim((string) $run->summary);
            $lines[] = '';
        }

        if ($findings->isNotEmpty()) {
            $lines[] = 'Findings you posted on this pull request:';

            foreach ($findings as $finding) {
                $lines[] = "- [{$finding->status->value}] {$finding->severity->value} {$finding->category->value} in {$finding->file_path} {$finding->lineLabel()}: {$finding->title}"
                    .($finding->agent !== null ? " (agent: {$finding->agent->name})" : '');
            }
        }

        $lines[] = '</review>';

        return implode("\n", $lines);
    }

    private function findingSection(ReviewFinding $finding): string
    {
        $lines = [
            '<finding>',
            'The question is asked in the thread of this finding, which you posted:',
            "{$finding->severity->value} {$finding->category->value} in {$finding->file_path} {$finding->lineLabel()} (confidence {$finding->confidence}%, risk {$finding->risk_score}/100)",
            "Title: {$finding->title}",
            $finding->body,
        ];

        if ($finding->code !== null) {
            $lines[] = "Code it points at:\n```\n{$finding->code}\n```";
        }

        if ($finding->suggestion !== null) {
            $lines[] = "Suggested replacement:\n```\n{$finding->suggestion}\n```";
        }

        if ($finding->verifier_note !== null) {
            $lines[] = "Verifier note: {$finding->verifier_note}";
        }

        $lines[] = '</finding>';

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $thread
     */
    private function threadSection(array $thread): string
    {
        $root = $thread[0];
        $lines = ['<thread path="'.($root['path'] ?? '').'" line="'.($root['line'] ?? $root['original_line'] ?? '').'">'];

        if (filled($root['diff_hunk'] ?? null)) {
            $lines[] = "Diff hunk:\n```diff\n".Str::limit((string) $root['diff_hunk'], 3000)."\n```";
        }

        foreach (array_slice($thread, -(int) config('reviews.conversation.max_history_messages')) as $comment) {
            $lines[] = '@'.($comment['user']['login'] ?? 'unknown').': '.Str::limit((string) ($comment['body'] ?? ''), 3000);
        }

        $lines[] = '</thread>';

        return implode("\n\n", $lines);
    }

    /**
     * Earlier questions and answers in the pull request conversation.
     */
    private function historySection(ConversationMessage $question): ?string
    {
        $history = $question->pullRequest->conversationMessages()
            ->whereNull('thread_comment_id')
            ->where('id', '<', $question->id)
            ->latest('id')
            ->limit((int) config('reviews.conversation.max_history_messages'))
            ->get()
            ->reverse();

        if ($history->isEmpty()) {
            return null;
        }

        $lines = ['<conversation>'];

        foreach ($history as $message) {
            $speaker = $message->role === ConversationRole::Bot ? 'You' : "@{$message->author_login}";
            $lines[] = "{$speaker}: ".Str::limit($message->body, 3000);
        }

        $lines[] = '</conversation>';

        return implode("\n\n", $lines);
    }

    /**
     * The discussed file first, then as much of the rest of the diff as the budget allows.
     */
    private function diffSection(ConversationMessage $question, ?string $focusPath): ?string
    {
        $files = collect($this->client->files($question->pullRequest))
            ->filter(fn (array $file): bool => isset($file['patch']) && ! $this->isIgnored((string) $file['filename']))
            ->sortBy(fn (array $file): int => $file['filename'] === $focusPath ? 0 : 1)
            ->values();

        $budget = (int) config('reviews.conversation.max_diff_characters');
        $parts = [];
        $omitted = 0;

        foreach ($files as $file) {
            $patch = FilePatch::fromGitHub((string) $file['filename'], (string) $file['status'], (string) $file['patch']);
            $annotated = $patch->annotated();

            if (strlen($annotated) > $budget) {
                $omitted++;

                continue;
            }

            $parts[] = "<file path=\"{$patch->path}\" status=\"{$patch->status}\">\n{$annotated}\n</file>";
            $budget -= strlen($annotated);
        }

        if ($parts === []) {
            return null;
        }

        if ($omitted > 0) {
            $parts[] = "({$omitted} more changed ".Str::plural('file', $omitted).' left out for length.)';
        }

        return "<diff>\n".implode("\n", $parts)."\n</diff>";
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
