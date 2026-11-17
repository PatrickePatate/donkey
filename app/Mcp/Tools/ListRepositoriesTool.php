<?php

namespace App\Mcp\Tools;

use App\Models\Repository;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-repositories')]
#[IsReadOnly]
#[Description('Lists the GitHub repositories connected to your current Donkey organization.')]
class ListRepositoriesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $organization = $request->user()->activeOrganization();

        if ($organization === null) {
            return Response::error('You have no current organization.');
        }

        return Response::json($organization->repositories()->orderBy('full_name')->get()->map(fn (Repository $repository): array => [
            'full_name' => $repository->full_name,
            'default_branch' => $repository->default_branch,
            'is_private' => $repository->is_private,
            'auto_review_enabled' => $repository->autoReviewEnabled(),
        ])->all());
    }
}
