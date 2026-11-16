<?php

namespace App\Services\Graph;

use App\Enums\GraphStatus;
use App\Models\Repository;
use App\Models\RepositoryGraph;
use Illuminate\Support\Str;

/**
 * Read-only navigation over a repository's latest kodus-graph, for the dependency explorer.
 *
 * @phpstan-type ExplorerNode array{id: string, name: string, label: string, kind: string, category: string, file: string, line: int, parent: ?string}
 * @phpstan-type ExplorerEdge array{source: string, target: string, kind: string}
 * @phpstan-type Neighbourhood array{node: ExplorerNode, callers: list<array{node: ExplorerNode, kind: string}>, dependencies: list<array{node: ExplorerNode, kind: string}>, members: list<ExplorerNode>, tests: list<ExplorerNode>}
 */
class GraphExplorer
{
    public const array CATEGORIES = [
        'module' => 'Modules',
        'class' => 'Classes',
        'service' => 'Services',
        'model' => 'Database models',
        'endpoint' => 'API endpoints',
        'method' => 'Methods',
        'test' => 'Tests',
    ];

    /**
     * @var array<int, array{nodes: array<string, ExplorerNode>, edges: list<ExplorerEdge>}>
     */
    private array $loaded = [];

    public function latestGraph(Repository $repository): ?RepositoryGraph
    {
        return $repository->graphs()
            ->where('status', GraphStatus::Ready)
            ->latest('built_at')
            ->get()
            ->first(fn (RepositoryGraph $graph): bool => $graph->isUsable());
    }

    /**
     * @return array{nodes: array<string, ExplorerNode>, edges: list<ExplorerEdge>}
     */
    public function load(RepositoryGraph $graph): array
    {
        if (isset($this->loaded[$graph->id])) {
            return $this->loaded[$graph->id];
        }

        $data = filesize((string) $graph->path) <= (int) config('graph.max_json_bytes')
            ? json_decode((string) file_get_contents((string) $graph->path), true)
            : null;

        $nodes = [];
        $edges = [];

        foreach (is_array($data) ? ($data['nodes'] ?? []) : [] as $raw) {
            if (! is_array($raw) || ! isset($raw['qualified_name'])) {
                continue;
            }

            $node = $this->node($raw);
            $nodes[$node['id']] = $node;
            $module = $this->moduleOf($node['file']);

            if ($module !== null && ! isset($nodes["module:{$module}"])) {
                $nodes["module:{$module}"] = ['id' => "module:{$module}", 'name' => $module, 'label' => $module, 'kind' => 'Module', 'category' => 'module', 'file' => $module, 'line' => 0, 'parent' => null];
            }

            if ($module !== null && $node['kind'] === 'Class') {
                $edges[] = ['source' => "module:{$module}", 'target' => $node['id'], 'kind' => 'CONTAINS'];
            }
        }

        foreach (is_array($data) ? ($data['edges'] ?? []) : [] as $raw) {
            $source = $raw['source_qualified'] ?? null;
            $target = $raw['target_qualified'] ?? null;

            if (is_string($source) && is_string($target) && isset($nodes[$source], $nodes[$target])) {
                $edges[] = ['source' => $source, 'target' => $target, 'kind' => (string) ($raw['kind'] ?? 'CALLS')];
            }
        }

        return $this->loaded[$graph->id] = ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @return array<string, int>
     */
    public function categoryCounts(RepositoryGraph $graph): array
    {
        $counts = array_fill_keys(array_keys(self::CATEGORIES), 0);

        foreach ($this->load($graph)['nodes'] as $node) {
            $counts[$node['category']]++;
        }

        return $counts;
    }

    /**
     * @return list<ExplorerNode>
     */
    public function search(RepositoryGraph $graph, string $query = '', ?string $category = null, int $limit = 50): array
    {
        $query = Str::lower(trim($query));
        $results = [];

        foreach ($this->load($graph)['nodes'] as $node) {
            if ($category !== null && $category !== '' && $node['category'] !== $category) {
                continue;
            }

            if ($query !== '' && ! Str::contains(Str::lower($node['label'].' '.$node['file']), $query)) {
                continue;
            }

            $results[] = $node;

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Callers, dependencies, members and tests of one node.
     *
     * @return ?Neighbourhood
     */
    public function neighbourhood(RepositoryGraph $graph, string $id, int $limit = 40): ?array
    {
        ['nodes' => $nodes, 'edges' => $edges] = $this->load($graph);

        if (! isset($nodes[$id])) {
            return null;
        }

        $callers = $dependencies = $members = $tests = [];

        foreach ($edges as $edge) {
            if ($edge['kind'] === 'CONTAINS') {
                if ($edge['source'] === $id) {
                    $members[$edge['target']] = $nodes[$edge['target']];
                }

                continue;
            }

            if ($edge['kind'] === 'TESTED_BY') {
                if ($edge['source'] === $id) {
                    $tests[$edge['target']] = $nodes[$edge['target']];
                }

                continue;
            }

            if ($edge['target'] === $id && $edge['source'] !== $id) {
                $callers[$edge['source']] ??= ['node' => $nodes[$edge['source']], 'kind' => $edge['kind']];
            } elseif ($edge['source'] === $id && $edge['target'] !== $id) {
                $dependencies[$edge['target']] ??= ['node' => $nodes[$edge['target']], 'kind' => $edge['kind']];
            }
        }

        return [
            'node' => $nodes[$id],
            'callers' => array_slice(array_values($callers), 0, $limit),
            'dependencies' => array_slice(array_values($dependencies), 0, $limit),
            'members' => array_slice(array_values($members), 0, $limit * 2),
            'tests' => array_values($tests),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return ExplorerNode
     */
    private function node(array $raw): array
    {
        $kind = (string) ($raw['kind'] ?? 'Method');
        $file = (string) ($raw['file_path'] ?? '');
        $name = (string) ($raw['name'] ?? '');
        $parent = isset($raw['parent_name']) ? (string) $raw['parent_name'] : null;

        return [
            'id' => (string) $raw['qualified_name'],
            'name' => $name,
            'label' => $kind === 'Method' && $parent ? "{$parent}::{$name}()" : $name,
            'kind' => $kind,
            'category' => $this->categorise($kind, $file, $name, (bool) ($raw['is_test'] ?? false)),
            'file' => $file,
            'line' => (int) ($raw['line_start'] ?? 0),
            'parent' => $parent,
        ];
    }

    private function categorise(string $kind, string $file, string $name, bool $isTest): string
    {
        $path = '/'.ltrim(str_replace('\\', '/', $file), '/');

        if ($isTest || $kind === 'Test' || preg_match('#/tests?/|Test\.php$|\.(test|spec)\.#i', $path)) {
            return 'test';
        }

        if (preg_match('#/(Http/Controllers|controllers|routes|api)/#i', $path) || preg_match('#/pages/.*\.blade\.php$#', $path)) {
            return $kind === 'Class' || $kind === 'Method' && ! str_starts_with($name, '__') ? 'endpoint' : 'method';
        }

        if ($kind !== 'Class') {
            return 'method';
        }

        if (preg_match('#/(Models|models|entities)/#', $path)) {
            return 'model';
        }

        if (preg_match('#/(Services|services|Actions|Jobs|Ai)/#', $path) || str_ends_with($name, 'Service')) {
            return 'service';
        }

        return 'class';
    }

    private function moduleOf(string $file): ?string
    {
        $directory = dirname(str_replace('\\', '/', $file));

        if ($directory === '.' || $directory === '') {
            return null;
        }

        return implode('/', array_slice(explode('/', $directory), 0, 2));
    }
}
