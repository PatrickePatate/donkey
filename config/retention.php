<?php

/*
|--------------------------------------------------------------------------
| Retention
|--------------------------------------------------------------------------
|
| How long records are kept before "model:prune" (scheduled daily) deletes
| them. Webhook payloads hold repository metadata and comment text, so they
| go first. Review findings keep only the code snippets they point at.
|
*/

return [

    'webhook_event_days' => (int) env('RETENTION_WEBHOOK_EVENT_DAYS', 30),

    'ai_usage_days' => (int) env('RETENTION_AI_USAGE_DAYS', 400),

    'audit_days' => (int) env('RETENTION_AUDIT_DAYS', 365),

    'conversation_days' => (int) env('RETENTION_CONVERSATION_DAYS', 365),

];
