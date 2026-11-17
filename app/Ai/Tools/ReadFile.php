<?php

namespace App\Ai\Tools;

use App\Services\Review\CodeExplorer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadFile implements Tool
{
    public function __construct(private CodeExplorer $explorer) {}

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): Stringable|string
    {
        return 'Read a file of the repository at the pull request head, with line numbers (up to 300 lines per call). '
            .'Use it to see whole functions, definitions or callers outside the diff.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->explorer->readFile(
            (string) ($request['path'] ?? ''),
            isset($request['start_line']) ? (int) $request['start_line'] : null,
            isset($request['end_line']) ? (int) $request['end_line'] : null,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Path from the repository root, e.g. app/Models/User.php.')->required(),
            'start_line' => $schema->integer()->min(1)->description('First line to read; defaults to 1.'),
            'end_line' => $schema->integer()->min(1)->description('Last line to read; defaults to 300 lines after start_line.'),
        ];
    }
}
