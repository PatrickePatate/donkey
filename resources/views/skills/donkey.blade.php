---
name: donkey
description: Use Donkey's code review knowledge for {!! $repository->full_name !!}. Use when fixing or discussing review findings, before changing code (to follow the team's learned conventions), or when you need an overview of the repository.
---

# Donkey

Donkey reviews the pull requests of `{!! $repository->full_name !!}`. Its MCP server gives read-only access to the review findings, the generated repository documentation and the team's review learnings.

## Setup

If the `donkey` MCP tools are not available, ask the user to connect the server (they sign in through the browser):

```bash
claude mcp add --transport http donkey {!! $mcpUrl !!}
```

Tools act on the user's current Donkey organization. Repositories are identified by their GitHub full name; for this repository use `{!! $repository->full_name !!}`.

## When to use which tool

- Starting work on an unfamiliar area: call `get-repository-documentation` for an architecture overview.
- Before writing or reviewing code: call `list-learnings` with `repository: "{!! $repository->full_name !!}"` and follow the conventions it returns.
- Addressing review feedback on a pull request: call `list-review-findings` with the pull request number, then fix each finding at its `file_path` and lines.
- Unsure of the repository name, or working across repositories: call `list-repositories`.

## Tools

@foreach ($tools as $tool)
### `{!! $tool['name'] !!}`

{!! $tool['description'] !!}

@php($properties = $tool['inputSchema']['properties'] ?? [])
@if ($properties === [] || $properties instanceof \stdClass)
No parameters.
@else
| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
@foreach ($properties as $parameter => $definition)
| `{!! $parameter !!}` | {!! $definition['type'] ?? 'any' !!} | {!! in_array($parameter, $tool['inputSchema']['required'] ?? [], true) ? 'yes' : 'no' !!} | {!! $definition['description'] ?? '' !!} |
@endforeach
@endif

@endforeach
