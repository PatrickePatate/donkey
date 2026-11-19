<?php

namespace App\Services\Usage;

use App\Enums\AiFeature;
use App\Enums\OrganizationRole;
use App\Mail\BudgetThresholdReached;
use App\Models\AiUsage;
use App\Models\ModelPrice;
use App\Models\Organization;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Records what each AI call cost an organization, and enforces its monthly budget.
 */
class UsageMeter
{
    public function record(
        Organization $organization,
        AiFeature $feature,
        ?string $provider,
        string $model,
        Usage $usage,
        ?Repository $repository = null,
        ?PullRequest $pullRequest = null,
        ?ReviewRun $reviewRun = null,
    ): AiUsage {
        $cacheReadTokens = $usage instanceof TextUsage ? (int) $usage->cacheReadInputTokens : 0;
        $cacheWriteTokens = $usage instanceof TextUsage ? (int) $usage->cacheWriteInputTokens : 0;

        $record = $organization->aiUsages()->create([
            'repository_id' => $repository->id ?? $pullRequest?->repository_id,
            'pull_request_id' => $pullRequest?->id,
            'review_run_id' => $reviewRun?->id,
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cache_write_tokens' => $cacheWriteTokens,
            'cost_micros' => $this->costMicros($model, $usage->inputTokens, $usage->outputTokens, $organization, $cacheReadTokens, $cacheWriteTokens),
        ]);

        if ($record->cost_micros > 0) {
            $this->alertOwnersOnThresholds($organization);
        }

        return $record;
    }

    /**
     * Estimated cost in millionths of a dollar; prices are per million tokens, so tokens × price works out exactly.
     * The cached tokens are a subset of the input tokens, billed at the cache prices instead of the input price.
     */
    public function costMicros(string $model, int $inputTokens, int $outputTokens, ?Organization $organization = null, int $cacheReadTokens = 0, int $cacheWriteTokens = 0): int
    {
        $price = $this->price($model, $organization);

        if ($price === null) {
            return 0;
        }

        $uncachedInputTokens = max(0, $inputTokens - $cacheReadTokens - $cacheWriteTokens);

        return (int) round(
            $uncachedInputTokens * $price['input']
            + $cacheReadTokens * $price['cache_read']
            + $cacheWriteTokens * $price['cache_write']
            + $outputTokens * $price['output']
        );
    }

    /**
     * Price the calls of a model that were recorded at zero cost because it had no price yet.
     */
    public function priceUnpricedUsage(Organization $organization, ModelPrice $modelPrice): void
    {
        $price = $this->price($modelPrice->model, $organization);

        $organization->aiUsages()
            ->where('model', $modelPrice->model)
            ->where('cost_micros', 0)
            ->update(['cost_micros' => DB::raw(sprintf(
                'CAST(ROUND((input_tokens - cache_read_tokens - cache_write_tokens) * %F + cache_read_tokens * %F + cache_write_tokens * %F + output_tokens * %F) AS BIGINT)',
                $price['input'], $price['cache_read'], $price['cache_write'], $price['output'],
            ))]);
    }

    /**
     * The organization's price for a model, else the configured one; cache prices default to a share of the input price.
     *
     * @return array{input: float, output: float, cache_read: float, cache_write: float}|null
     */
    private function price(string $model, ?Organization $organization): ?array
    {
        $custom = $organization?->modelPrices()->firstWhere('model', $model);
        $price = $custom !== null
            ? ['input' => $custom->input_per_million, 'output' => $custom->output_per_million, 'cache_read' => $custom->cache_read_per_million, 'cache_write' => $custom->cache_write_per_million]
            : config("ai_pricing.models.{$model}");

        if (!is_array($price)) {
            return null;
        }

        $input = (float) $price['input'];

        return [
            'input' => $input,
            'output' => (float) $price['output'],
            'cache_read' => (float) ($price['cache_read'] ?? $input * config('ai_pricing.cache_read_ratio')),
            'cache_write' => (float) ($price['cache_write'] ?? $input * config('ai_pricing.cache_write_ratio')),
        ];
    }

    /**
     * What the organization spent this calendar month, in millionths of a dollar.
     */
    public function monthToDateMicros(Organization $organization): int
    {
        return (int) $organization->aiUsages()->where('created_at', '>=', now()->startOfMonth())->sum('cost_micros');
    }

    /**
     * Whether new AI work should be refused. Without a budget, never.
     */
    public function isOverBudget(Organization $organization): bool
    {
        return $organization->monthly_budget_cents !== null
            && $this->monthToDateMicros($organization) >= $organization->monthly_budget_cents * 10_000;
    }

    private function alertOwnersOnThresholds(Organization $organization): void
    {
        if ($organization->monthly_budget_cents === null || $organization->monthly_budget_cents === 0) {
            return;
        }

        $share = $this->monthToDateMicros($organization) / ($organization->monthly_budget_cents * 10_000);

        foreach (config('ai_pricing.alert_thresholds') as $threshold) {
            $key = "budget-alert:{$organization->id}:".now()->format('Y-m').":{$threshold}";

            if ($share < $threshold || !Cache::add($key, true, now()->endOfMonth()->addDay())) {
                continue;
            }

            $owners = $organization->members()->wherePivot('role', OrganizationRole::Owner->value)->get();

            foreach ($owners as $owner) {
                Mail::to($owner)->queue(new BudgetThresholdReached($organization, (float) $threshold));
            }
        }
    }
}
