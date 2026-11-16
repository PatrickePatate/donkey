<?php

use App\Services\Review\ReviewCommand;

it('parses review commands addressed to the bot', function (string $body, ?string $expected) {
    expect(ReviewCommand::parse($body, 'donkey')?->name)->toBe($expected);
})->with([
    'review' => ['@donkey review', 'review'],
    'full review, any case' => ['Hey @Donkey Full Review please', 'full review'],
    'unknown command' => ['@donkey dance', 'unknown'],
    'other handle' => ['@someone review', null],
    'quoted' => ["> @donkey review\nthanks", null],
    'inside code fence' => ["```\n@donkey review\n```", null],
    'email-like text' => ['ping me at me@donkey review', null],
]);
