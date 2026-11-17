<?php

namespace App\Services\Graph;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper around the @kodus/kodus-graph CLI.
 */
class KodusGraph
{
    /**
     * Whether the CLI can be run here; cached briefly so a missing binary is cheap to detect.
     */
    public function isAvailable(): bool
    {
        if (! config('graph.enabled')) {
            return false;
        }

        return Cache::remember('kodus-graph:available', now()->addMinutes(5), function (): bool {
            return Process::timeout(30)->env($this->environment())->run([config('graph.binary'), '--version'])->successful();
        });
    }

    public function parse(string $repoDirectory, string $outputPath): ProcessResult
    {
        return $this->run([
            'parse', '--all',
            '--repo-dir', $repoDirectory,
            '--out', $outputPath,
            '--min-confidence', (string) config('graph.min_confidence'),
            '--max-memory', (string) config('graph.max_memory_mb'),
        ], (int) config('graph.parse_timeout'));
    }

    /**
     * Re-parse only files whose content changed since the graph was built.
     */
    public function update(string $repoDirectory, string $graphPath): ProcessResult
    {
        return $this->run([
            'update',
            '--repo-dir', $repoDirectory,
            '--graph', $graphPath,
            '--out', $graphPath,
        ], (int) config('graph.parse_timeout'));
    }

    /**
     * Review context for changed files, compared against a base-branch graph.
     *
     * @param  list<string>  $files
     * @param  'prompt'|'xml'|'json'  $format
     */
    public function context(string $repoDirectory, string $graphPath, array $files, string $diffPath, string $format, string $outputPath): ProcessResult
    {
        // PR file names are attacker-controlled: a file named "--out" would be read as a flag, so skip them.
        $files = array_values(array_filter($files, fn (string $file): bool => ! str_starts_with($file, '-')));

        return $this->run([
            'context',
            '--files', ...$files,
            '--repo-dir', $repoDirectory,
            '--graph', $graphPath,
            '--diff', $diffPath,
            '--format', $format,
            '--min-confidence', (string) config('graph.min_confidence'),
            '--max-depth', (string) config('graph.max_depth'),
            '--max-prompt-chars', (string) config('graph.max_prompt_characters'),
            '--out', $outputPath,
        ], (int) config('graph.context_timeout'));
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $extra = trim((string) config('graph.extra_path'));

        if ($extra === '') {
            return [];
        }

        return ['PATH' => $extra.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments, int $timeout): ProcessResult
    {
        $result = Process::timeout($timeout)->env($this->environment())->run([config('graph.binary'), ...$arguments]);

        if ($result->failed()) {
            throw new GraphException("kodus-graph {$arguments[0]} failed: ".trim($result->errorOutput() ?: $result->output()));
        }

        return $result;
    }
}
