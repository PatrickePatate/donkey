<?php

namespace App\Enums;

enum FindingVerification: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Skipped = 'skipped';
}
