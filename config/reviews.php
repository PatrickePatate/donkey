<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Review model
    |--------------------------------------------------------------------------
    |
    | The laravel/ai provider and model used for pull request reviews. The
    | provider must be configured in config/ai.php with its API key.
    |
    */

    'provider' => env('REVIEW_AI_PROVIDER', env('AI_PROVIDER', 'anthropic')),

    'model' => env('REVIEW_AI_MODEL', 'claude-sonnet-5-5'),

    'timeout' => (int) env('REVIEW_AI_TIMEOUT', 180),

    'eco' => [
        'model' => env('REVIEW_ECO_MODEL', 'claude-haiku-5-5'),
        'small_chunk_characters' => (int) env('REVIEW_ECO_SMALL_CHUNK_CHARACTERS', 8_000),
        'verifier_max_findings' => (int) env('REVIEW_ECO_VERIFIER_MAX_FINDINGS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Guard rails against huge pull requests: files beyond these limits are
    | skipped, and the diff is split into chunks reviewed one at a time.
    |
    */

    'max_files' => (int) env('REVIEW_MAX_FILES', 60),

    'max_patch_characters' => (int) env('REVIEW_MAX_PATCH_CHARACTERS', 40_000),

    'chunk_characters' => (int) env('REVIEW_CHUNK_CHARACTERS', 60_000),

    'max_instruction_characters' => (int) env('REVIEW_MAX_INSTRUCTION_CHARACTERS', 12_000),

    /*
    |--------------------------------------------------------------------------
    | Ignored paths
    |--------------------------------------------------------------------------
    |
    | Glob patterns for files that are never sent to the model.
    |
    */

    'ignored_paths' => [
        '*.lock',
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
        '*.min.js',
        '*.min.css',
        '*.map',
        '*.svg',
        'vendor/*',
        'node_modules/*',
        'public/build/*',
        'dist/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Repository instruction files
    |--------------------------------------------------------------------------
    |
    | Coding-agent instruction files read from the repository and given to
    | the reviewer, from the root and from each changed file's directories.
    |
    */

    'instruction_files' => ['CLAUDE.md', 'AGENTS.md'],

    'check_run_name' => 'Donkey review',

    /*
    |--------------------------------------------------------------------------
    | Verifier
    |--------------------------------------------------------------------------
    |
    | A second, independent model pass that re-checks every finding that would
    | be published, with the surrounding code, and rejects false positives.
    |
    */

    'verifier' => [
        'enabled' => (bool) env('REVIEW_VERIFIER_ENABLED', true),
        'model' => env('REVIEW_VERIFIER_MODEL', env('REVIEW_AI_MODEL', 'claude-sonnet-5-5')),
        'context_lines' => (int) env('REVIEW_VERIFIER_CONTEXT_LINES', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Graph impact boost
    |--------------------------------------------------------------------------
    |
    | Findings inside changed functions that other code depends on get their
    | risk score multiplied by up to (1 + max_impact_boost).
    |
    */

    'max_impact_boost' => (float) env('REVIEW_MAX_IMPACT_BOOST', 0.3),

    /*
    |--------------------------------------------------------------------------
    | Correctness gate
    |--------------------------------------------------------------------------
    |
    | Bug, logic and regression findings below the risk threshold are still
    | sent to the verifier and published when it confirms them, as long as
    | they reach this severity and confidence. Requires the verifier.
    |
    */

    'correctness_gate' => [
        'enabled' => (bool) env('REVIEW_CORRECTNESS_GATE_ENABLED', true),
        'min_severity' => env('REVIEW_CORRECTNESS_GATE_MIN_SEVERITY', 'medium'),
        'min_confidence' => (int) env('REVIEW_CORRECTNESS_GATE_MIN_CONFIDENCE', 70),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    |
    | Answers to questions asked to the bot in pull request comments. The diff
    | budget bounds how much code goes into each answer's prompt.
    |
    */

    'conversation' => [
        'model' => env('CONVERSATION_AI_MODEL', env('REVIEW_AI_MODEL', 'claude-sonnet-5-5')),
        'max_diff_characters' => (int) env('CONVERSATION_MAX_DIFF_CHARACTERS', 40_000),
        'max_history_messages' => (int) env('CONVERSATION_MAX_HISTORY_MESSAGES', 12),
        'max_findings' => 20,
    ],

];
