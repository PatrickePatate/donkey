<?php

namespace App\Services\Review;

/**
 * Everything the reviewer needs for one run: the files to review and the repository's instructions.
 */
final class ReviewContext
{
    /**
     * @param  array<string, FilePatch>  $files  Files to review, keyed by path.
     * @param  array<string, string>  $instructions  Instruction file path => content.
     * @param  list<string>  $skippedFiles  Paths left out (ignored, binary or over the limits).
     * @param  ?string  $documentation  The repository guide, if enabled and available.
     * @param  list<string>  $removedFiles  Deleted files: never reviewed, but the code graph checks what still uses them.
     */
    public function __construct(
        public readonly string $baseSha,
        public readonly bool $isIncremental,
        public readonly array $files,
        public readonly array $instructions,
        public readonly array $skippedFiles,
        public readonly ?string $documentation = null,
        public readonly array $removedFiles = [],
    ) {
    }

    /**
     * The same context restricted to the files that pass the filter, e.g. an agent's globs.
     *
     * @param  callable(FilePatch): bool  $filter
     */
    public function onlyFiles(callable $filter): self
    {
        return new self(
            baseSha: $this->baseSha,
            isIncremental: $this->isIncremental,
            files: array_filter($this->files, $filter),
            instructions: $this->instructions,
            skippedFiles: $this->skippedFiles,
            documentation: $this->documentation,
            removedFiles: $this->removedFiles,
        );
    }

    public function file(string $path): ?FilePatch
    {
        return $this->files[$path] ?? null;
    }

    /**
     * Size of the change and how many added lines are comments, a cheap sign of over-commented code.
     *
     * @return array{lines_added: int, lines_removed: int, comment_lines: int}
     */
    public function diffMetrics(): array
    {
        $metrics = ['lines_added' => 0, 'lines_removed' => 0, 'comment_lines' => 0];

        foreach ($this->files as $file) {
            $metrics['lines_added'] += count($file->addedLines);
            $metrics['lines_removed'] += preg_match_all('/^-/m', $file->patch);

            foreach ($file->addedLines as $line) {
                // shortcut: line-prefix detection, misses trailing comments and lines inside multi-line strings.
                if (preg_match('~^\s*(//|#(?!\[)|/\*|\*|\{\{--|<!--)~', $file->commentableLines[$line]) === 1) {
                    $metrics['comment_lines']++;
                }
            }
        }

        return $metrics;
    }

    /**
     * Group files into prompts that stay under the character budget.
     *
     * @return list<list<FilePatch>>
     */
    public function chunks(int $maxCharacters): array
    {
        $chunks = [];
        $current = [];
        $size = 0;

        foreach ($this->files as $file) {
            $length = strlen($file->patch);

            if ($current !== [] && $size + $length > $maxCharacters) {
                $chunks[] = $current;
                $current = [];
                $size = 0;
            }

            $current[] = $file;
            $size += $length;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
