<?php

/*
|--------------------------------------------------------------------------
| Review agent templates
|--------------------------------------------------------------------------
|
| Starting points offered when an organization creates a review agent. Every
| field can be edited before saving; nothing here runs on its own.
|
*/

return [

    'templates' => [

        'security' => [
            'name' => 'Security',
            'description' => 'Vulnerabilities, secrets and access control.',
            'file_globs' => [],
            'categories' => ['security'],
            'severity_floor' => 'medium',
            'instructions' => <<<'MD'
            Review the change like an application security engineer. Look for:
            - Injection: SQL built by string concatenation, shell commands, unescaped output (XSS), template injection.
            - Broken access control: missing authorization checks, IDOR, trusting ids or roles from the request.
            - Secrets and sensitive data: hard-coded keys or tokens, credentials in logs, personal data in responses.
            - Unsafe deserialization, SSRF from user-supplied URLs, path traversal in file access, weak crypto.
            Explain how an attacker would exploit each finding. Do not report theoretical issues without a realistic path.
            MD,
        ],

        'laravel' => [
            'name' => 'Laravel',
            'description' => 'Framework conventions and common Laravel pitfalls.',
            'file_globs' => ['*.php', 'routes/*', 'config/*', 'database/*'],
            'categories' => ['bug', 'performance', 'maintainability'],
            'severity_floor' => 'medium',
            'instructions' => <<<'MD'
            Review the change as an experienced Laravel developer. Look for:
            - N+1 queries, missing eager loading, queries inside loops, unbounded `get()` on large tables.
            - Mass assignment holes, validation missing or bypassed, authorization not done through policies or gates.
            - Jobs that are not idempotent, missing database transactions around multi-step writes.
            - Business logic in controllers or Blade views that belongs in actions, services or models.
            - Configuration read with `env()` outside config files.
            Prefer the framework's built-in features over custom code.
            MD,
        ],

        'architecture' => [
            'name' => 'Architecture',
            'description' => 'Boundaries, coupling and design decisions.',
            'file_globs' => [],
            'categories' => ['maintainability', 'logic'],
            'severity_floor' => 'medium',
            'instructions' => <<<'MD'
            Review the design of the change rather than individual lines. Look for:
            - Layer violations, e.g. domain code calling HTTP or UI code, or modules reaching into each other's internals.
            - New coupling that will make the code hard to change: hidden global state, god classes, circular dependencies.
            - Duplicated logic that already exists elsewhere in the code you can see.
            - Public APIs or contracts changed without updating their callers.
            Only report problems with a concrete cost; leave matters of taste alone.
            MD,
        ],

        'testing' => [
            'name' => 'Testing',
            'description' => 'Missing or weak tests for risky changes.',
            'file_globs' => [],
            'categories' => ['maintainability', 'bug'],
            'severity_floor' => 'medium',
            'instructions' => <<<'MD'
            Review how well the change is tested. Look for:
            - New behaviour, branches or bug fixes without a test that would fail if the code were wrong.
            - Tests that assert nothing meaningful, mock the code under test, or depend on time, order or network.
            - Edge cases the change clearly introduces (empty input, nulls, limits, permissions) that no test covers.
            Anchor each finding on the untested code in the diff and describe the test you would add.
            MD,
        ],

        'performance' => [
            'name' => 'Performance',
            'description' => 'Slow queries, heavy loops and memory use.',
            'file_globs' => [],
            'categories' => ['performance'],
            'severity_floor' => 'medium',
            'instructions' => <<<'MD'
            Review the change for performance problems that matter in production. Look for:
            - Database work in loops, missing indexes implied by new queries, loading whole tables into memory.
            - Repeated expensive calls (HTTP, file system, hashing) that could be batched or cached.
            - Algorithmic blow-ups: nested loops over growing collections, quadratic string building.
            - Work done synchronously in a request that belongs in a queue.
            Estimate the impact (data size, request frequency) for each finding.
            MD,
        ],

    ],

];
