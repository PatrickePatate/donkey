<?php

return [

    'delay_minutes' => (int) env('DOCS_DELAY_MINUTES', 10),

    'key_files' => array_filter(array_map('trim', explode(',', env('DOCS_KEY_FILES', 'README.md,composer.json,package.json')))),

    'source_extensions' => ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'py', 'go', 'rs', 'java', 'rb'],

    'max_files' => (int) env('DOCS_MAX_FILES', 50),

    'max_file_characters' => (int) env('DOCS_MAX_FILE_CHARACTERS', 12_000),

    'max_source_characters' => (int) env('DOCS_MAX_SOURCE_CHARACTERS', 100_000),

    'max_tree_entries' => (int) env('DOCS_MAX_TREE_ENTRIES', 2_500),

    'max_review_characters' => (int) env('DOCS_MAX_REVIEW_CHARACTERS', 8_000),

];
