<?php

namespace App\Services\Review;

/**
 * One changed file and the lines of its new version that GitHub lets us comment on.
 */
final class FilePatch
{
    /**
     * @param  array<int, string>  $commentableLines  New-file line number => line content (added and context lines).
     * @param  array<int, int>  $hunkOfLine  New-file line number => index of the hunk it belongs to.
     * @param  list<int>  $addedLines  New-file line numbers added by this change.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $status,
        public readonly string $patch,
        public readonly array $commentableLines,
        public readonly array $hunkOfLine,
        public readonly array $addedLines,
    ) {}

    public static function fromGitHub(string $path, string $status, string $patch): self
    {
        $commentable = [];
        $hunkOfLine = [];
        $added = [];
        $hunk = -1;
        $newLine = 0;

        foreach (preg_split('/\R/', $patch) ?: [] as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $matches) === 1) {
                $hunk++;
                $newLine = (int) $matches[1];

                continue;
            }

            if ($hunk < 0 || str_starts_with($line, '\\')) {
                continue;
            }

            $marker = $line[0] ?? ' ';
            $content = substr($line, 1);

            if ($marker === '-') {
                continue;
            }

            $commentable[$newLine] = $content;
            $hunkOfLine[$newLine] = $hunk;

            if ($marker === '+') {
                $added[] = $newLine;
            }

            $newLine++;
        }

        return new self($path, $status, $patch, $commentable, $hunkOfLine, $added);
    }

    public function canComment(int $line): bool
    {
        return array_key_exists($line, $this->commentableLines);
    }

    /**
     * GitHub multi-line comments must stay within one hunk.
     */
    public function canCommentRange(int $startLine, int $endLine): bool
    {
        return $this->canComment($startLine)
            && $this->canComment($endLine)
            && $startLine <= $endLine
            && $this->hunkOfLine[$startLine] === $this->hunkOfLine[$endLine];
    }

    /**
     * Content of the new-file lines in a range, as they appear in the diff.
     */
    public function codeBetween(int $startLine, int $endLine): string
    {
        $lines = [];

        for ($line = $startLine; $line <= $endLine; $line++) {
            if ($this->canComment($line)) {
                $lines[] = $this->commentableLines[$line];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The patch rewritten with new-file line numbers, so the model can cite exact lines.
     */
    public function annotated(): string
    {
        $output = [];
        $newLine = 0;

        foreach (preg_split('/\R/', $this->patch) ?: [] as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $matches) === 1) {
                $newLine = (int) $matches[1];
                $output[] = $line;

                continue;
            }

            $marker = $line[0] ?? ' ';

            if ($marker === '\\') {
                continue;
            }

            if ($marker === '-') {
                $output[] = '      - '.substr($line, 1);

                continue;
            }

            $output[] = str_pad((string) $newLine, 5, ' ', STR_PAD_LEFT).' '.$marker.' '.substr($line, 1);
            $newLine++;
        }

        return implode("\n", $output);
    }
}
