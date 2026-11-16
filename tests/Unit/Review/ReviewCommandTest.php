<?php

use App\Enums\BotCommandType;
use App\Services\Review\ReviewCommand;

it('parses commands addressed to the bot', function (string $body, ?BotCommandType $expected) {
    expect(ReviewCommand::parse($body, 'donkey')?->type)->toBe($expected);
})->with([
    'review' => ['@donkey review', BotCommandType::Review],
    'full review, any case' => ['Hey @Donkey Full Review please', BotCommandType::FullReview],
    'run an agent' => ['@donkey run security', BotCommandType::RunAgent],
    'list agents' => ['@donkey agents', BotCommandType::ListAgents],
    'ignore' => ['@donkey ignore', BotCommandType::Ignore],
    'false positive with punctuation' => ['@donkey false positive.', BotCommandType::Ignore],
    'pause' => ['@donkey pause', BotCommandType::Pause],
    'resume' => ['@donkey: resume', BotCommandType::Resume],
    'bare mention' => ['@donkey', BotCommandType::Help],
    'question' => ['@donkey why is this an injection?', BotCommandType::Ask],
    'ignore inside a sentence is a question' => ['@donkey should I ignore this?', BotCommandType::Ask],
    'other handle' => ['@someone review', null],
    'quoted' => ["> @donkey review\nthanks", null],
    'inside code fence' => ["```\n@donkey review\n```", null],
    'email-like text' => ['ping me at me@donkey review', null],
]);

it('keeps the agent slug and the full question text', function () {
    expect(ReviewCommand::parse('@donkey run Laravel-Conventions', 'donkey'))
        ->type->toBe(BotCommandType::RunAgent)
        ->arguments->toBe('laravel-conventions');

    expect(ReviewCommand::parse("@donkey is this safe?\nThe input is validated upstream.", 'donkey'))
        ->type->toBe(BotCommandType::Ask)
        ->arguments->toBe("is this safe?\nThe input is validated upstream.");
});
