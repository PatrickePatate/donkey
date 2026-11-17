<?php

namespace App\Actions;

use App\Mcp\Servers\DonkeyServer;
use App\Models\Repository;
use App\Services\GitHub\PullRequestClient;

/**
 * Opens a pull request adding a Claude Code skill that teaches agents to use Donkey's MCP server.
 */
class ProposeDonkeySkill
{
    public const PATH = '.claude/skills/donkey/SKILL.md';

    public const BRANCH = 'donkey/claude-skill';

    public function __construct(private PullRequestClient $github)
    {
    }

    /**
     * @return string The pull request's web URL.
     */
    public function handle(Repository $repository): string
    {
        return $this->github->proposeFile(
            $repository,
            self::BRANCH,
            self::PATH,
            $this->render($repository),
            'Add the Donkey skill for Claude Code',
            'Adds `'.self::PATH."`, which tells Claude Code how to use Donkey's MCP server (review findings, documentation and team learnings for `{$repository->full_name}`).",
        );
    }

    public function render(Repository $repository): string
    {
        return view('skills.donkey', [
            'repository' => $repository,
            'mcpUrl' => url('/mcp'),
            'tools' => array_map(fn (string $tool): array => app($tool)->toArray(), DonkeyServer::TOOLS),
        ])->render();
    }
}
