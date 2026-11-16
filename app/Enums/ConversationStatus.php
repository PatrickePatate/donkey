<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Failed = 'failed';
}
