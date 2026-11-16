<?php

namespace App\Services\Review;

/**
 * A command addressed to the bot in a pull request comment, e.g. "@donkey full review".
 */
final class ReviewCommand
{
    public function __construct(public readonly string $name, public readonly string $arguments) {}

    /**
     * Find the first "@handle <command>" in a comment, ignoring quoted and fenced text.
     */
    public static function parse(string $body, string $handle): ?self
    {
        $withoutCode = preg_replace('/```.*?```/s', '', $body) ?? $body;

        foreach (preg_split('/\R/', $withoutCode) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }

            if (preg_match('/(?:^|\s)@'.preg_quote($handle, '/').'\b\s*(.*)$/i', $line, $matches) === 1) {
                $text = trim($matches[1]);
                $name = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));

                foreach (['full review', 'review'] as $command) {
                    if ($name === $command || str_starts_with($name, $command.' ')) {
                        return new self($command, trim(substr($text, strlen($command))));
                    }
                }

                return new self('unknown', $text);
            }
        }

        return null;
    }

    public function isReview(): bool
    {
        return in_array($this->name, ['review', 'full review'], true);
    }

    public function isFullReview(): bool
    {
        return $this->name === 'full review';
    }
}
