<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetRepositoryDocumentationTool;
use App\Mcp\Tools\ListLearningsTool;
use App\Mcp\Tools\ListRepositoriesTool;
use App\Mcp\Tools\ListReviewFindingsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Donkey')]
#[Version('1.0.0')]
#[Instructions('Read-only access to the code reviews of your current Donkey organization: connected repositories, review findings, generated repository documentation and active review learnings. Repositories are identified by their GitHub full name, e.g. "acme/api".')]
class DonkeyServer extends Server
{
    /**
     * Also read by the repository skill generator.
     *
     * @var list<class-string<Tool>>
     */
    public const TOOLS = [
        ListRepositoriesTool::class,
        ListReviewFindingsTool::class,
        GetRepositoryDocumentationTool::class,
        ListLearningsTool::class,
    ];

    protected array $tools = self::TOOLS;
}
