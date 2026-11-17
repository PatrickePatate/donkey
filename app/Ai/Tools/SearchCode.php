<?php

namespace App\Ai\Tools;

use App\Services\Review\CodeExplorer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchCode implements Tool
{
    public function __construct(private CodeExplorer $explorer) {}

    public function name(): string
    {
        return 'search_code';
    }

    public function description(): Stringable|string
    {
        return 'Find the lines of the repository, at the pull request head, that contain a text (exact, case-sensitive). '
            .'Use it to find every use of a function, method, config key, route or column.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->explorer->search((string) ($request['text'] ?? ''), isset($request['path']) ? (string) $request['path'] : null);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('Exact text to find, at least 3 characters, e.g. "->charge(" or "billing.currency".')->required(),
            'path' => $schema->string()->description('Optional git pathspec to narrow the search, e.g. app/ or *.blade.php.'),
        ];
    }
}
