<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Code graph (kodus-graph)
    |--------------------------------------------------------------------------
    |
    | Structural analysis of repositories with @kodus/kodus-graph, a Bun CLI.
    | When disabled or unavailable, reviews run without graph context.
    |
    | Both Bun and kodus-graph are pinned devDependencies in package.json, so
    | `composer setup` (npm install + npm run graph:setup) installs them into
    | node_modules/.bin. Point these at a global install to use one instead.
    |
    */

    'enabled' => (bool) env('GRAPH_ENABLED', true),

    'binary' => env('KODUS_GRAPH_BINARY', base_path('node_modules/.bin/kodus-graph')),

    /*
    | Directories prepended to PATH when running kodus-graph. Its launcher runs
    | "env bun", so Bun's bin directory must be reachable (e.g. ~/.bun/bin).
    */
    'extra_path' => env('GRAPH_EXTRA_PATH', base_path('node_modules/.bin')),

    'git_binary' => env('GIT_BINARY', 'git'),

    /*
    | Where repository checkouts and graph files live. Both are caches and can
    | be deleted at any time; they are rebuilt on the next review.
    */
    'workspace_path' => env('GRAPH_WORKSPACE_PATH', storage_path('app/private/workspaces')),

    'graph_path' => env('GRAPH_STORAGE_PATH', storage_path('app/private/graphs')),

    /*
    | Number of base graphs kept per repository.
    */
    'keep_graphs' => (int) env('GRAPH_KEEP', 3),

    'parse_timeout' => (int) env('GRAPH_PARSE_TIMEOUT', 600),

    'context_timeout' => (int) env('GRAPH_CONTEXT_TIMEOUT', 180),

    'git_timeout' => (int) env('GRAPH_GIT_TIMEOUT', 300),

    'max_memory_mb' => (int) env('GRAPH_MAX_MEMORY_MB', 1024),

    'min_confidence' => (float) env('GRAPH_MIN_CONFIDENCE', 0.5),

    'max_depth' => (int) env('GRAPH_MAX_DEPTH', 3),

    'max_prompt_characters' => (int) env('GRAPH_MAX_PROMPT_CHARACTERS', 16_000),

    /*
    | context --format json embeds the whole merged graph; above this size we
    | keep the prompt block but skip the structured data.
    */
    'max_json_bytes' => (int) env('GRAPH_MAX_JSON_BYTES', 64 * 1024 * 1024),

];
