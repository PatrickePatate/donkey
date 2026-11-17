<?php

namespace App\Services\Graph;

use App\Enums\RunGraphStatus;
use App\Models\RepositoryGraph;

final class GraphContextResult
{
    /**
     * @param  ?RepositoryGraph  $baseGraph  The base-branch graph the context was computed against, for the review tools.
     */
    public function __construct(
        public readonly RunGraphStatus $status,
        public readonly ?GraphContext $context = null,
        public readonly ?string $error = null,
        public readonly ?RepositoryGraph $baseGraph = null,
    ) {}
}
