<?php

namespace App\Services\Graph;

use App\Enums\RunGraphStatus;

final class GraphContextResult
{
    public function __construct(
        public readonly RunGraphStatus $status,
        public readonly ?GraphContext $context = null,
        public readonly ?string $error = null,
    ) {}
}
