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
        $record = $organization->aiUsages()->create([
            'repository_id' => $repository->id ?? $pullRequest?->repository_id,
            'pull_request_id' => $pullRequest?->id,
            'review_run_id' => $reviewRun?->id,
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_micros' => $this->costMicros($model, $usage->inputTokens, $usage->outputTokens, $organization),
        ]);

        if ($record->cost_micros > 0) {
            $this->alertOwnersOnThresholds($organization);
        }

        return $record;
    }

    /**
     * Estimated cost in millionths of a dollar; prices are per million tokens, so tokens × price works out exactly.
     */
    public function costMicros(string $model, int $inputTokens, int $outputTokens, ?Organization $organization = null): int
    {
        $custom = $organization?->modelPrices()->firstWhere('model', $model);
        $price = $custom !== null
            ? ['input' => $custom->input_per_million, 'output' => $custom->output_per_million]
            : config("ai_pricing.models.{$model}");

        if (! is_array($price)) {
            return 0;
        }

        return (int) round($inputTokens * (float) $price['input'] + $outputTokens * (float) $price['output']);
    }

    /**
     * Price the calls of a model that were recorded at zero cost because it had no price yet.
     */
    public function priceUnpricedUsage(Organization $organization, ModelPrice $price): void
    {
        $organization->aiUsages()
            ->where('model', $price->model)
            ->where('cost_micros', 0)
            ->update(['cost_micros' => DB::raw("CAST(ROUND(input_tokens * {$price->input_per_million} + output_tokens * {$price->output_per_million}) AS BIGINT)")]);
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

            if ($share < $threshold || ! Cache::add($key, true, now()->endOfMonth()->addDay())) {
                continue;
            }

            $owners = $organization->members()->wherePivot('role', OrganizationRole::Owner->value)->get();

            foreach ($owners as $owner) {
                Mail::to($owner)->queue(new BudgetThresholdReached($organization, (float) $threshold));
            }
        }
    }
}
