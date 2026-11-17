<?php

namespace App\Ai\Tools;

use App\Services\Review\CodeExplorer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SymbolGraph implements Tool
{
    public function __construct(private CodeExplorer $explorer) {}

    public function name(): string
    {
        return 'symbol_graph';
    }

    public function description(): Stringable|string
    {
        return 'Callers, dependencies and tests of a function, method or class, from the code graph of the base branch. '
            .'Accepts a name like Billing.charge, charge, or a full id from an earlier answer.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->explorer->symbol((string) ($request['name'] ?? ''));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Symbol name, e.g. Billing.charge, or a full id such as app/Services/Billing.php::Billing.charge.')->required(),
        ];
    }
}
