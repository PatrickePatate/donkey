<?php

namespace App\Mcp\Tools;

use App\Models\ReviewFinding;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-review-findings')]
#[IsReadOnly]
#[Description('Lists the most recent review findings for a repository, optionally for one pull request.')]
class ListReviewFindingsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'repository' => ['required', 'string'],
            'pull_request' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $repository = $request->user()->activeOrganization()?->repositories()->where('full_name', $validated['repository'])->first();

        if ($repository === null) {
            return Response::error("Repository {$validated['repository']} not found in your current organization.");
        }

        $findings = ReviewFinding::query()
            ->with('pullRequest:id,number,title')
            ->whereHas('pullRequest', fn ($query) => $query
                ->where('repository_id', $repository->id)
                ->when($validated['pull_request'] ?? null, fn ($query, int $number) => $query->where('number', $number)))
            ->latest('id')
            ->limit($validated['limit'] ?? 25)
            ->get();

        return Response::json($findings->map(fn (ReviewFinding $finding): array => [
            'pull_request' => $finding->pullRequest->number,
            'pull_request_title' => $finding->pullRequest->title,
            'title' => $finding->title,
            'body' => $finding->body,
            'suggestion' => $finding->suggestion,
            'severity' => $finding->severity?->value,
            'category' => $finding->category?->value,
            'status' => $finding->status?->value,
            'file_path' => $finding->file_path,
            'start_line' => $finding->start_line,
            'end_line' => $finding->end_line,
        ])->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repository' => $schema->string()->description('GitHub full name, e.g. "acme/api".')->required(),
            'pull_request' => $schema->integer()->description('Only findings of this pull request number.'),
            'limit' => $schema->integer()->description('Maximum findings to return (1-100, default 25).'),
        ];
    }
}
