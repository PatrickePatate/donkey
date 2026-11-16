<?php

namespace App\Enums;

enum ReviewStage: string
{
    case Collecting = 'collecting';
    case Analyzing = 'analyzing';
    case Validating = 'validating';
    case Publishing = 'publishing';

    public function label(): string
    {
        return match ($this) {
            self::Collecting => 'Collect',
            self::Analyzing => 'Analyze',
            self::Validating => 'Validate',
            self::Publishing => 'Publish',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Collecting => 'Diff and repository instructions',
            self::Analyzing => 'Model reviews the changes',
            self::Validating => 'Line checks, dedupe, risk gate',
            self::Publishing => 'Inline comments on GitHub',
        };
    }

    /**
     * Steps for the progress stepper, in pipeline order.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function steps(): array
    {
        return array_map(fn (self $stage): array => [
            'key' => $stage->value,
            'label' => $stage->label(),
            'description' => $stage->description(),
        ], self::cases());
    }
}
