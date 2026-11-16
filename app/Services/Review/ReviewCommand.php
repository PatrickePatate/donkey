<?php

namespace App\Services\Review;

use App\Enums\BotCommandType;

/**
 * A comment addressed to the bot, e.g. "@donkey full review", "@donkey run security" or a free-form question.
 */
final class ReviewCommand
{
    /**
     * Exact phrases for commands without arguments. Anything else after the mention is a question.
     *
     * @var array<string, BotCommandType>
     */
    private const PHRASES = [
        'review' => BotCommandType::Review,
        'full review' => BotCommandType::FullReview,
        'agents' => BotCommandType::ListAgents,
        'list agents' => BotCommandType::ListAgents,
        'ignore' => BotCommandType::Ignore,
        'dismiss' => BotCommandType::Ignore,
        'false positive' => BotCommandType::Ignore,
        'pause' => BotCommandType::Pause,
        'resume' => BotCommandType::Resume,
        'help' => BotCommandType::Help,
    ];

    /**
     * @param  string  $arguments  The agent slug for "run", the question for "ask".
     */
    public function __construct(public readonly BotCommandType $type, public readonly string $arguments = '') {}

    /**
     * Find the first "@handle …" mention in a comment, ignoring quoted and fenced text.
     */
    public static function parse(string $body, string $handle): ?self
    {
        $withoutCode = preg_replace('/```.*?```/s', '', $body) ?? $body;
        $mention = '/(?:^|\s)@'.preg_quote($handle, '/').'\b/i';

        foreach (preg_split('/\R/', $withoutCode) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }

            if (preg_match('/(?:^|\s)@'.preg_quote($handle, '/').'\b[,:]?\s*(.*)$/i', $line, $matches) !== 1) {
                continue;
            }

            $text = trim($matches[1]);
            $phrase = strtolower(rtrim((string) preg_replace('/\s+/', ' ', $text), ' .!'));

            if ($phrase === '') {
                return new self(BotCommandType::Help);
            }

            if (isset(self::PHRASES[$phrase])) {
                return new self(self::PHRASES[$phrase]);
            }

            // "review" followed by anything still means a review, as it always has.
            if (str_starts_with($phrase, 'full review ')) {
                return new self(BotCommandType::FullReview, trim(substr($text, strlen('full review'))));
            }

            if (str_starts_with($phrase, 'review ')) {
                return new self(BotCommandType::Review, trim(substr($text, strlen('review'))));
            }

            if (preg_match('/^run\s+([a-z0-9][a-z0-9-]*)$/', $phrase, $agent) === 1) {
                return new self(BotCommandType::RunAgent, $agent[1]);
            }

            $question = trim((string) preg_replace($mention, ' ', $body));

            return new self(BotCommandType::Ask, $question);
        }

        return null;
    }

    public function isReview(): bool
    {
        return in_array($this->type, [BotCommandType::Review, BotCommandType::FullReview], true);
    }

    public function isFullReview(): bool
    {
        return $this->type === BotCommandType::FullReview;
    }
}
