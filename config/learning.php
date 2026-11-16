<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | When a provider is set, memories and learnings are embedded and stored in
    | pgvector, and the most similar ones to each pull request go into the
    | review prompt. Without one, they are ranked by path, pin and weight.
    | The provider must support embeddings in config/ai.php (e.g. openai).
    |
    */

    'embeddings' => [
        'provider' => env('LEARNING_EMBEDDINGS_PROVIDER'),
        'model' => env('LEARNING_EMBEDDINGS_MODEL'),
        'dimensions' => (int) env('LEARNING_EMBEDDINGS_DIMENSIONS', 1536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Review prompt budget
    |--------------------------------------------------------------------------
    */

    'max_memories' => (int) env('LEARNING_MAX_MEMORIES', 15),

    'max_learnings' => (int) env('LEARNING_MAX_LEARNINGS', 20),

    'max_characters' => (int) env('LEARNING_MAX_CHARACTERS', 8_000),

    /*
    |--------------------------------------------------------------------------
    | Proposing learnings
    |--------------------------------------------------------------------------
    |
    | Feedback on findings (dismissals, 👎 reactions, findings left untouched
    | at merge) is periodically turned into proposed learnings that admins
    | approve. The synthesizer looks at this many recent signals at once.
    |
    */

    'synthesis' => [
        'model' => env('LEARNING_AI_MODEL', env('REVIEW_AI_MODEL', 'claude-sonnet-5-5')),
        'max_signals' => (int) env('LEARNING_MAX_SIGNALS', 40),
        'delay_minutes' => (int) env('LEARNING_SYNTHESIS_DELAY', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Decay
    |--------------------------------------------------------------------------
    |
    | Active learnings that stop matching anything lose weight every day and
    | are archived once they fall below the floor. Each match restores some.
    |
    */

    'decay' => [
        'idle_days' => (int) env('LEARNING_DECAY_IDLE_DAYS', 30),
        'daily_factor' => (float) env('LEARNING_DECAY_DAILY_FACTOR', 0.98),
        'archive_below' => (float) env('LEARNING_ARCHIVE_BELOW', 0.3),
        'reinforcement' => 0.15,
        'max_weight' => 2.0,
    ],

];
