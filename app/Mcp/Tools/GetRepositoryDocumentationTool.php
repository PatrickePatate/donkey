<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-repository-documentation')]
#[IsReadOnly]
#[Description('Returns the Markdown documentation Donkey generated for a repository.')]
class GetRepositoryDocumentationTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['repository' => ['required', 'string']]);

        $repository = $request->user()->activeOrganization()?->repositories()->where('full_name', $validated['repository'])->first();

        if ($repository === null) {
            return Response::error("Repository {$validated['repository']} not found in your current organization.");
        }

        $content = $repository->document?->content;

        return $content ? Response::text($content) : Response::error("No documentation has been generated for {$repository->full_name} yet.");
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repository' => $schema->string()->description('GitHub full name, e.g. "acme/api".')->required(),
        ];
    }
}
