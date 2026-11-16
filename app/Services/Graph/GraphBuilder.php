<?php

namespace App\Services\Graph;

use App\Enums\GraphStatus;
use App\Models\Repository;
use App\Models\RepositoryGraph;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Builds and caches base-branch graphs, incrementally from the latest graph when possible.
 */
class GraphBuilder
{
    public function __construct(private KodusGraph $kodusGraph) {}

    /**
     * Return a ready graph for the commit, building it if needed. Call inside the workspace lock.
     */
    public function ensure(RepositoryWorkspace $workspace, Repository $repository, string $sha): RepositoryGraph
    {
        $existing = $repository->graphs()->where('commit_sha', $sha)->first();

        if ($existing?->isUsable()) {
            return $existing;
        }

        $graph = $repository->graphs()->updateOrCreate(
            ['commit_sha' => $sha],
            ['status' => GraphStatus::Building, 'error' => null, 'path' => $this->pathFor($repository, $sha)],
        );

        $startedAt = hrtime(true);

        try {
            $directory = $workspace->checkout($repository, $sha);
            File::ensureDirectoryExists(dirname((string) $graph->path));

            $previous = $repository->graphs()
                ->where('status', GraphStatus::Ready)
                ->whereKeyNot($graph->id)
                ->latest('built_at')
                ->first();

            $incremental = false;

            if ($previous?->isUsable()) {
                try {
                    File::copy((string) $previous->path, (string) $graph->path);
                    $this->kodusGraph->update($directory, (string) $graph->path);
                    $incremental = true;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            if (! $incremental) {
                $this->kodusGraph->parse($directory, (string) $graph->path);
            }

            $graph->update([
                'status' => GraphStatus::Ready,
                'is_incremental' => $incremental,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                'size_bytes' => (int) filesize((string) $graph->path),
                'built_at' => now(),
                ...$this->metadata((string) $graph->path),
            ]);
        } catch (Throwable $exception) {
            $graph->update(['status' => GraphStatus::Failed, 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
            File::delete((string) $graph->path);

            throw $exception instanceof GraphException ? $exception : new GraphException($exception->getMessage(), previous: $exception);
        }

        $this->prune($repository);

        return $graph->fresh();
    }

    private function pathFor(Repository $repository, string $sha): string
    {
        return rtrim((string) config('graph.graph_path'), '/')."/{$repository->id}/{$sha}.json";
    }

    /**
     * Node/edge/file counts from the graph metadata, when the file is small enough to decode.
     *
     * @return array{nodes_count?: int, edges_count?: int, files_count?: int}
     */
    private function metadata(string $path): array
    {
        if (filesize($path) > (int) config('graph.max_json_bytes')) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        $metadata = is_array($data) ? ($data['metadata'] ?? []) : [];

        return [
            'nodes_count' => (int) ($metadata['total_nodes'] ?? count($data['nodes'] ?? [])),
            'edges_count' => (int) ($metadata['total_edges'] ?? count($data['edges'] ?? [])),
            'files_count' => (int) ($metadata['files_parsed'] ?? 0),
        ];
    }

    /**
     * Keep only the most recent graphs per repository.
     */
    private function prune(Repository $repository): void
    {
        $repository->graphs()
            ->where('status', GraphStatus::Ready)
            ->latest('built_at')
            ->skip((int) config('graph.keep_graphs'))
            ->take(PHP_INT_MAX)
            ->get()
            ->each(function (RepositoryGraph $graph): void {
                File::delete((string) $graph->path);
                $graph->delete();
            });
    }
}
