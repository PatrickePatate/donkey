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

];
