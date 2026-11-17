<?php

namespace App\Services\Review;

use App\Ai\Tools\ReadFile;
use App\Ai\Tools\SearchCode;
use App\Ai\Tools\SymbolGraph;
use App\Models\Repository;
use App\Models\RepositoryGraph;
use App\Services\Graph\GraphExplorer;
use App\Services\Graph\RepositoryWorkspace;
use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;

/**
 * What the reviewer and the verifier may look up beyond the diff: files and text search at the pull request head
 * (from the graph workspace) and the base-branch code graph. Every call goes to the run's live log.
 */
class CodeExplorer
{
    private const MAX_FILE_LINES = 300;

    private const MAX_SEARCH_RESULTS = 40;

    private const MAX_LINE_CHARACTERS = 240;

    /**
     * @param  ?Closure(string): void  $log
     */
    public function __construct(
        private RepositoryWorkspace $workspace,
        private GraphExplorer $graphExplorer,
        private Repository $repository,
        private string $headSha,
        private ?RepositoryGraph $graph = null,
        private ?Closure $log = null,
    ) {}

    /**
     * @return list<Tool>
     */
    public function tools(): array
    {
        return [new ReadFile($this), new SearchCode($this), ...($this->graph !== null ? [new SymbolGraph($this)] : [])];
    }

    public function readFile(string $path, ?int $startLine = null, ?int $endLine = null): string
    {
        $path = ltrim(trim($path), './');
        $content = $path === '' ? null : $this->workspace->show($this->repository, $this->headSha, $path);

        if ($content === null) {
            return $this->logged('read_file', $path, 'not found', "No file at {$path} in the pull request head.");
        }

        $lines = preg_split('/\R/', rtrim($content, "\n")) ?: [];
        $total = count($lines);
        $from = max(1, min($startLine ?? 1, $total));
        $to = min($total, max($from, $endLine ?? $from + self::MAX_FILE_LINES - 1), $from + self::MAX_FILE_LINES - 1);
        $numbered = [];

        for ($line = $from; $line <= $to; $line++) {
            $numbered[] = str_pad((string) $line, 5, ' ', STR_PAD_LEFT).'  '.Str::limit($lines[$line - 1], self::MAX_LINE_CHARACTERS);
        }

        return $this->logged(
            'read_file',
            "{$path}:{$from}-{$to}",
            ($to - $from + 1).' of '.$total.' lines',
            "{$path} lines {$from}-{$to} of {$total}".($to < $total ? ' (call again with start_line for more)' : '')."\n".implode("\n", $numbered),
        );
    }

    public function search(string $text, ?string $path = null): string
    {
        $text = trim($text);
        $where = filled($path) ? " in {$path}" : '';

        if (mb_strlen($text) < 3) {
            return $this->logged('search_code', "\"{$text}\"{$where}", 'rejected', 'Search for at least 3 characters.');
        }

        $matches = $this->workspace->grep($this->repository, $this->headSha, $text, $path);
        $shown = array_map(fn (string $match): string => Str::limit($match, self::MAX_LINE_CHARACTERS), array_slice($matches, 0, self::MAX_SEARCH_RESULTS));
        $count = count($matches).' '.Str::plural('match', count($matches));

        return $this->logged(
            'search_code',
            "\"{$text}\"{$where}",
            $count,
            $matches === []
                ? "No line contains \"{$text}\"{$where} in the pull request head."
                : "{$count} (path:line:content)".(count($matches) > count($shown) ? ', first '.count($shown).' shown' : '')."\n".implode("\n", $shown),
        );
    }

    public function symbol(string $name): string
    {
        $name = trim($name);
        $nodes = $this->graph === null || $name === '' ? [] : $this->graphExplorer->load($this->graph)['nodes'];
        $needle = Str::lower($name);
        $matches = array_values(array_filter($nodes, fn (array $node): bool => Str::lower($node['id']) === $needle
            || Str::endsWith(Str::lower($node['id']), ['::'.$needle, '.'.$needle])
            || Str::lower($node['name']) === $needle));

        if (count($matches) !== 1) {
            $candidates = array_map(fn (array $node): string => "- {$node['id']} ({$node['kind']}, {$node['file']}:{$node['line']})", array_slice($matches, 0, 15));

            return $this->logged(
                'symbol_graph',
                $name,
                $matches === [] ? 'not found' : count($matches).' candidates',
                $matches === []
                    ? "No symbol named {$name} in the base-branch graph (symbols added by this pull request are not in it). Try search_code."
                    : "Several symbols match {$name}; call again with one of these ids:\n".implode("\n", $candidates),
            );
        }

        $hood = $this->graphExplorer->neighbourhood($this->graph, $matches[0]['id'], 25);
        $list = fn (array $entries): string => $entries === [] ? '  (none)' : implode("\n", array_map(
            fn (array $entry): string => '  - '.($entry['node']['id'] ?? $entry['id'])
                .' '.($entry['node']['file'] ?? $entry['file']).':'.($entry['node']['line'] ?? $entry['line'])
                .(isset($entry['kind'], $entry['node']) ? " [{$entry['kind']}]" : ''),
            $entries,
        ));

        return $this->logged(
            'symbol_graph',
            $name,
            count($hood['callers']).' '.Str::plural('caller', count($hood['callers'])).', '.count($hood['dependencies']).' '.Str::plural('dependency', count($hood['dependencies'])),
            implode("\n", [
                "{$hood['node']['id']} ({$hood['node']['kind']}) at {$hood['node']['file']}:{$hood['node']['line']}, from the base-branch graph.",
                'Callers and users:', $list($hood['callers']),
                'Dependencies:', $list($hood['dependencies']),
                'Tests:', $list($hood['tests']),
            ]),
        );
    }

    /**
     * Log the call as "tool arguments → outcome" (the live log renders that shape) and return the result.
     */
    private function logged(string $tool, string $arguments, string $outcome, string $result): string
    {
        if ($this->log !== null) {
            rescue(fn () => ($this->log)(Str::limit("{$tool} {$arguments}", 200).' → '.$outcome));
        }

        return $result;
    }
}
