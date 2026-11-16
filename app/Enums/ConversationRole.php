<?php

namespace App\Enums;

enum ConversationRole: string
{
    /** A comment written by a person on GitHub. */
    case User = 'user';

    /** A reply posted by the bot. */
    case Bot = 'bot';
}
