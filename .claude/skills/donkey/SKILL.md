---
name: donkey
description: Use Donkey's code review knowledge for code16/donkey. Use when fixing or discussing review findings, before changing code (to follow the team's learned conventions), or when you need an overview of the repository.
---

# Donkey

Donkey reviews the pull requests of `code16/donkey`. Its MCP server gives read-only access to the review findings, the generated repository documentation and the team's review learnings.

## Setup

If the `donkey` MCP tools are not available, ask the user to connect the server (they sign in through the browser):

```bash
claude mcp add --transport http donkey https://donkey.code16.fr/mcp
```

Tools act on the user's current Donkey organization. Repositories are identified by their GitHub full name; for this repository use `code16/donkey`.

## When to use which tool

- Starting work on an unfamiliar area: call `get-repository-documentation` for an architecture overview.
- Before writing or reviewing code: call `list-learnings` with `repository: "code16/donkey"` and follow the conventions it returns.
- Addressing review feedback on a pull request: call `list-review-findings` with the pull request number, then fix each finding at its `file_path` and lines.
- Unsure of the repository name, or working across repositories: call `list-repositories`.

## Tools

### `list-repositories`

Lists the GitHub repositories connected to your current Donkey organization.

No parameters.

### `list-review-findings`

Lists the most recent review findings for a repository, optionally for one pull request.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `repository` | string | yes | GitHub full name, e.g. "acme/api". |
| `pull_request` | integer | no | Only findings of this pull request number. |
| `limit` | integer | no | Maximum findings to return (1-100, default 25). |

### `get-repository-documentation`

Returns the Markdown documentation Donkey generated for a repository.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `repository` | string | yes | GitHub full name, e.g. "acme/api". |

### `list-learnings`

Lists the active review learnings (team conventions Donkey applies when reviewing), organization-wide or for one repository.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `repository` | string | no | GitHub full name; includes organization-wide learnings too. Omit for all learnings. |

