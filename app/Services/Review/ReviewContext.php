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
     */
    public function __construct(
        public readonly string $baseSha,
        public readonly bool $isIncremental,
        public readonly array $files,
        public readonly array $instructions,
        public readonly array $skippedFiles,
        public readonly ?string $documentation = null,
    ) {}

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
        );
    }

    public function file(string $path): ?FilePatch
    {
        return $this->files[$path] ?? null;
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
