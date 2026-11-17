<?php

namespace App\Mcp\Tools;

use App\Enums\LearningStatus;
use App\Models\Learning;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-learnings')]
#[IsReadOnly]
#[Description('Lists the active review learnings (team conventions Donkey applies when reviewing), organization-wide or for one repository.')]
class ListLearningsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['repository' => ['nullable', 'string']]);

        $organization = $request->user()->activeOrganization();

        if ($organization === null) {
            return Response::error('You have no current organization.');
        }

        $query = Learning::query()->with('repository:id,full_name')->where('status', LearningStatus::Active);

        if (isset($validated['repository'])) {
            $repository = $organization->repositories()->where('full_name', $validated['repository'])->first();

            if ($repository === null) {
                return Response::error("Repository {$validated['repository']} not found in your current organization.");
            }

            $query->forRepository($repository);
        } else {
            $query->where('organization_id', $organization->id);
        }

        return Response::json($query->orderByDesc('weight')->get()->map(fn (Learning $learning): array => [
            'scope' => $learning->scopeLabel(),
            'kind' => $learning->kind->value,
            'category' => $learning->category?->value,
            'path_glob' => $learning->path_glob,
            'body' => $learning->body,
            'rationale' => $learning->rationale,
        ])->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repository' => $schema->string()->description('GitHub full name; includes organization-wide learnings too. Omit for all learnings.'),
        ];
    }
}
