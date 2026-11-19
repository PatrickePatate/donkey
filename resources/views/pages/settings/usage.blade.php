<?php

use App\Models\Organization;
use App\Services\Audit\AuditLog;
use App\Services\Usage\UsageMeter;
use App\Services\Usage\UsageReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Usage & cost')] class extends Component
{
    #[Url]
    public string $month = '';

    public string $budget = '';

    /** @var list<array{model: string, input: string, output: string}> */
    public array $prices = [];

    public string $newModel = '';

    public function mount(): void
    {
        Gate::authorize('viewUsage', $this->organization);

        if (preg_match('/^\d{4}-\d{2}$/', $this->month) !== 1) {
            $this->month = now()->format('Y-m');
        }

        $this->loadPrices();
        $this->budget = $this->organization->monthly_budget_cents !== null ? number_format($this->organization->monthly_budget_cents / 100, 2, '.', '') : '';
    }

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        return app(UsageReport::class)->forMonth($this->organization, Carbon::createFromFormat('Y-m', $this->month)->startOfMonth());
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function months(): array
    {
        return collect(range(0, 11))
            ->mapWithKeys(fn (int $ago): array => [now()->startOfMonth()->subMonthsNoOverflow($ago)->format('Y-m') => now()->startOfMonth()->subMonthsNoOverflow($ago)->format('F Y')])
            ->all();
    }

    public function updatedMonth(): void
    {
        if (!array_key_exists($this->month, $this->months)) {
            $this->month = now()->format('Y-m');
        }

        unset($this->report);
    }

    public function saveBudget(): void
    {
        Gate::authorize('viewUsage', $this->organization);

        $this->validate(['budget' => ['nullable', 'numeric', 'min:0', 'max:1000000']]);

        $cents = $this->budget === '' ? null : (int) round((float) $this->budget * 100);
        $previous = $this->organization->monthly_budget_cents;

        $this->organization->update(['monthly_budget_cents' => $cents]);
        app(AuditLog::class)->record($this->organization, 'organization.budget_changed', $this->organization, ['monthly_budget_cents' => [$previous, $cents]]);

        $this->dispatch('close-dialog');
        $this->dispatch('toast', message: $cents === null ? 'Budget removed.' : 'Budget saved.', variant: 'success');
    }

    /**
     * One row per model that has a custom price or was used without any price.
     */
    private function loadPrices(): void
    {
        $custom = $this->organization->modelPrices()->orderBy('model')->get()->keyBy('model');
        $unpriced = $this->organization->aiUsages()->distinct()->pluck('model')
            ->reject(fn (string $model): bool => $custom->has($model) || config("ai_pricing.models.{$model}") !== null);

        $trim = fn (?string $value): string => $value === null ? '' : rtrim(rtrim($value, '0'), '.');

        $this->prices = $custom->map(fn ($price): array => ['model' => $price->model, 'input' => $trim($price->input_per_million), 'output' => $trim($price->output_per_million), 'cache_read' => $trim($price->cache_read_per_million), 'cache_write' => $trim($price->cache_write_per_million)])
            ->concat($unpriced->map(fn (string $model): array => ['model' => $model, 'input' => '', 'output' => '', 'cache_read' => '', 'cache_write' => '']))
            ->values()
            ->all();
    }

    public function addModel(): void
    {
        $this->validate(['newModel' => ['required', 'string', 'max:255']]);

        $this->prices[] = ['model' => trim($this->newModel), 'input' => '', 'output' => '', 'cache_read' => '', 'cache_write' => ''];
        $this->newModel = '';
    }

    /**
     * Drops the custom price of a model, so it falls back to the default price (or none).
     */
    public function removeModel(int $index): void
    {
        Gate::authorize('viewUsage', $this->organization);

        $model = $this->prices[$index]['model'] ?? null;

        if ($model === null) {
            return;
        }

        if ($this->organization->modelPrices()->where('model', $model)->delete() > 0) {
            app(AuditLog::class)->record($this->organization, 'organization.prices_changed', $this->organization, ['removed' => $model]);
        }

        unset($this->prices[$index], $this->report);
        $this->prices = array_values($this->prices);
        $this->dispatch('toast', message: "Removed the price of {$model}.", variant: 'success');
    }

    public function savePrices(): void
    {
        Gate::authorize('viewUsage', $this->organization);

        $this->validate([
            'prices.*.model' => ['required', 'string', 'max:255'],
            'prices.*.input' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'prices.*.output' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'prices.*.cache_read' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'prices.*.cache_write' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        foreach ($this->prices as $row) {
            if ($row['input'] === '' || $row['output'] === '') {
                $this->organization->modelPrices()->where('model', $row['model'])->delete();

                continue;
            }

            // A removed price is soft deleted and keeps its row, so saving the model again brings it back.
            $price = $this->organization->modelPrices()->withTrashed()->updateOrCreate(['model' => $row['model']], [
                'input_per_million' => $row['input'],
                'output_per_million' => $row['output'],
                'cache_read_per_million' => ($row['cache_read'] ?? '') === '' ? null : $row['cache_read'],
                'cache_write_per_million' => ($row['cache_write'] ?? '') === '' ? null : $row['cache_write'],
            ]);
            $price->trashed() && $price->restore();
            app(UsageMeter::class)->priceUnpricedUsage($this->organization, $price);
        }

        app(AuditLog::class)->record($this->organization, 'organization.prices_changed', $this->organization, ['models' => array_column($this->prices, 'model')]);

        unset($this->report);
        $this->loadPrices();
        $this->dispatch('toast', message: 'Prices saved.', variant: 'success');
    }

    public function money(int $micros): string
    {
        return UsageReport::money($micros);
    }
};
?>

@php
    $report = $this->report;
    $budgetMicros = $this->organization->monthly_budget_cents !== null ? $this->organization->monthly_budget_cents * 10_000 : null;
    $isCurrentMonth = $month === now()->format('Y-m');
    $share = $budgetMicros ? $report['cost'] / max(1, $budgetMicros) : null;
    $maxDay = max(1, collect($report['days'])->max('cost'));
    $delta = $report['previous_cost'] > 0 ? (int) round(100 * ($report['cost'] - $report['previous_cost']) / $report['previous_cost']) : null;
    $tokens = $report['input_tokens'] + $report['output_tokens'];
    $averageDay = count($report['days']) > 0 ? intdiv($report['cost'], count($report['days'])) : 0;
@endphp

<div class="grid gap-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-2xl tracking-tight">Usage & cost</h1>
            <p class="max-w-2xl text-sm text-muted-foreground">Estimated AI spend of {{ $this->organization->name }}, from token counts and model prices. Only owners see this page.</p>
        </div>
        <x-ui.select wire:model.live="month" aria-label="Month" class="w-44">
            @foreach ($this->months as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-ui.select>
    </div>

    @if ($isCurrentMonth && $budgetMicros !== null && $report['cost'] >= $budgetMicros)
        <x-ui.alert variant="destructive" title="Monthly budget reached">
            Reviews, answers on GitHub and learning proposals are paused until next month. Raise the budget below to resume them now.
        </x-ui.alert>
    @endif

    {{-- Key figures --}}
    <div class="grid divide-y rounded-xl border bg-card shadow-xs sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
        <div class="grid content-start gap-1 p-5">
            <span class="text-sm text-muted-foreground">Spent</span>
            <span class="font-display text-3xl font-medium tabular-nums">{{ $this->money($report['cost']) }}</span>
            @if ($delta !== null)
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span @class(['inline-flex items-center gap-0.5 rounded px-1 py-0.5 font-medium tabular-nums', 'bg-destructive/10 text-destructive' => $delta > 0, 'bg-success/10 text-success' => $delta <= 0])>
                        <x-ui.icon :name="$delta > 0 ? 'trending-up' : 'trending-down'" class="size-3" /> {{ sprintf('%+d', $delta) }}%
                    </span>
                    vs {{ $this->money($report['previous_cost']) }} last month
                </span>
            @else
                <span class="text-xs text-muted-foreground">No spend the month before</span>
            @endif
        </div>
        <div class="grid content-start gap-1 p-5">
            <span class="text-sm text-muted-foreground">{{ $isCurrentMonth ? 'Projected' : 'Daily average' }}</span>
            <span class="font-display text-3xl font-medium tabular-nums">{{ $this->money($isCurrentMonth ? (int) $report['projected'] : $averageDay) }}</span>
            <span class="text-xs text-muted-foreground">
                @if ($isCurrentMonth)
                    At this month's pace{{ $budgetMicros !== null && $report['projected'] > $budgetMicros ? ', over budget' : '' }}
                @else
                    Over {{ count($report['days']) }} days
                @endif
            </span>
        </div>
        <div class="grid content-start gap-1 p-5">
            <span class="text-sm text-muted-foreground">Budget</span>
            @if ($budgetMicros !== null)
                <span class="font-display text-3xl font-medium tabular-nums">{{ (int) round($share * 100) }}<span class="text-lg text-muted-foreground">%</span></span>
                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-label="Budget used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round(min(1, $share) * 100) }}">
                    <div @class(['h-full rounded-full', 'bg-destructive' => $share >= 1, 'bg-warning' => $share >= 0.8 && $share < 1, 'bg-foreground/70' => $share < 0.8]) style="width: {{ min(1, $share) * 100 }}%"></div>
                </div>
                <span class="text-xs text-muted-foreground">of {{ $this->money($budgetMicros) }} a month · <button type="button" x-on:click="$dispatch('open-dialog', 'budget')" class="underline-offset-4 hover:text-foreground hover:underline">Change</button></span>
            @else
                <span class="font-display text-3xl font-medium text-muted-foreground">No limit</span>
                <button type="button" x-on:click="$dispatch('open-dialog', 'budget')" class="justify-self-start text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">Set a monthly budget</button>
            @endif
        </div>
        <div class="grid content-start gap-1 p-5">
            <span class="text-sm text-muted-foreground">AI calls</span>
            <span class="font-display text-3xl font-medium tabular-nums">{{ number_format($report['calls']) }}</span>
            <span class="text-xs text-muted-foreground">{{ Number::abbreviate($tokens, maxPrecision: 1) }} tokens · {{ Number::abbreviate($report['input_tokens'], maxPrecision: 1) }} in, {{ Number::abbreviate($report['output_tokens'], maxPrecision: 1) }} out</span>
        </div>
    </div>

    {{-- Daily spend --}}
    <section class="grid gap-3">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="text-lg">Daily spend</h2>
            @if ($report['cost'] > 0)
                <span class="text-xs text-muted-foreground">Peak {{ $this->money(collect($report['days'])->max('cost')) }} · average {{ $this->money($averageDay) }}</span>
            @endif
        </div>

        @if ($report['cost'] === 0)
            <p class="rounded-xl border border-dashed py-12 text-center text-sm text-muted-foreground">No AI usage in {{ $this->months[$month] }}.</p>
        @else
            <div x-data="{ hover: null }" class="rounded-xl border bg-card p-5 shadow-xs">
                <div class="relative h-44">
                    <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
                        @foreach ([1, 0.5, 0] as $fraction)
                            <div class="flex items-center gap-2">
                                <span class="w-12 shrink-0 text-right text-[10px] text-muted-foreground tabular-nums">{{ $this->money((int) ($maxDay * $fraction)) }}</span>
                                <span class="h-px flex-1 border-t border-dashed"></span>
                            </div>
                        @endforeach
                    </div>
                    <div class="absolute inset-y-0 right-0 left-14 flex items-end gap-[3px]" role="img" aria-label="Daily spend for {{ $this->months[$month] }}" x-on:mouseleave="hover = null">
                        @foreach ($report['days'] as $day)
                            <div class="relative flex h-full flex-1 items-end" x-on:mouseenter="hover = {{ $loop->index }}">
                                <div class="w-full rounded-t-[3px] transition-colors" x-bind:class="hover === null || hover === {{ $loop->index }} ? 'bg-[var(--chart-1)]' : 'bg-[var(--chart-1)]/40'" style="height: {{ $day['cost'] > 0 ? max(2, 100 * $day['cost'] / $maxDay) : 0 }}%"></div>
                                <div x-cloak x-show="hover === {{ $loop->index }}" class="absolute bottom-full left-1/2 z-10 mb-2 -translate-x-1/2 rounded-md border bg-popover px-2 py-1 text-xs whitespace-nowrap shadow-md">
                                    <span class="text-muted-foreground">{{ Carbon::parse($day['date'])->format('D, M j') }}</span>
                                    <span class="ml-1 font-medium tabular-nums">{{ $this->money($day['cost']) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="mt-2 ml-14 flex justify-between text-xs text-muted-foreground">
                    <span>{{ Carbon::parse($report['days'][0]['date'])->format('M j') }}</span>
                    <span>{{ Carbon::parse(end($report['days'])['date'])->format('M j') }}</span>
                </div>
            </div>
        @endif
    </section>

    @if ($report['calls'] > 0)
        {{-- Where it goes --}}
        <section class="grid gap-3">
            <h2 class="text-lg">Where it goes</h2>
            <div class="grid gap-6 rounded-xl border bg-card p-5 shadow-xs lg:grid-cols-2 lg:gap-10">
                <div class="grid content-start gap-4">
                    <h3 class="font-sans text-sm font-medium">By feature</h3>
                    <div class="flex h-3 gap-0.5 overflow-hidden rounded-full bg-muted" role="img" aria-label="Spend by feature">
                        @foreach ($report['features'] as $row)
                            <span style="width: {{ 100 * $row['cost'] / max(1, $report['cost']) }}%; background: var(--chart-{{ $loop->index % 6 + 1 }})" title="{{ $row['label'] }}: {{ $this->money($row['cost']) }}"></span>
                        @endforeach
                    </div>
                    <ul class="grid gap-2 text-sm">
                        @foreach ($report['features'] as $row)
                            <li class="flex items-center gap-2">
                                <span class="size-2.5 shrink-0 rounded-[3px]" style="background: var(--chart-{{ $loop->index % 6 + 1 }})"></span>
                                <span class="flex-1 truncate">{{ $row['label'] }}</span>
                                <span class="text-xs text-muted-foreground tabular-nums">{{ number_format($row['calls']) }} {{ str('call')->plural($row['calls']) }}</span>
                                <span class="w-20 text-right tabular-nums">{{ $this->money($row['cost']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="grid content-start gap-4">
                    <h3 class="font-sans text-sm font-medium">By repository</h3>
                    <ul class="grid gap-3 text-sm">
                        @foreach ($report['repositories'] as $row)
                            <li class="grid gap-1">
                                <div class="flex items-center justify-between gap-4">
                                    <span class="truncate font-mono text-xs">{{ $row['label'] }}</span>
                                    <span class="tabular-nums">{{ $this->money($row['cost']) }}</span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div class="h-full rounded-full bg-foreground/60" style="width: {{ 100 * $row['cost'] / max(1, $report['cost']) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="grid content-start gap-3">
                <h2 class="text-lg">By model</h2>
                <div class="table-wrap">
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Model</th>
                                    <th class="num">Tokens in / out</th>
                                    <th class="num">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['models'] as $row)
                                    <tr>
                                        <td class="font-mono text-xs">{{ $row['label'] }}</td>
                                        <td class="num text-muted-foreground">{{ Number::abbreviate($row['input_tokens'], maxPrecision: 1) }} / {{ Number::abbreviate($row['output_tokens'], maxPrecision: 1) }}</td>
                                        <td class="num">
                                            {{ $this->money($row['cost']) }}
                                            @unless (config('ai_pricing.models.'.$row['label']) || collect($prices)->contains(fn ($price) => $price['model'] === $row['label'] && $price['input'] !== ''))
                                                <x-ui.icon name="triangle-alert" class="ml-1 inline size-3.5 text-warning" title="No price configured for this model" />
                                            @endunless
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="grid content-start gap-3">
                <h2 class="text-lg">Most expensive pull requests</h2>
                <div class="table-wrap">
                    @if ($report['pull_requests'] === [])
                        <p class="py-8 text-center text-sm text-muted-foreground">No pull request reviews this month.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Pull request</th>
                                        <th class="num">Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report['pull_requests'] as $row)
                                        <tr>
                                            <td class="max-w-0 w-full"><a href="{{ route('pull-requests.show', $row['id']) }}" wire:navigate class="block truncate hover:underline">{{ $row['label'] }}</a></td>
                                            <td class="num">{{ $this->money($row['cost']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    @endif

    {{-- Settings --}}
    <div class="grid divide-y border-t">
        <section class="grid gap-6 py-8 md:grid-cols-[15rem_minmax(0,1fr)] md:gap-10">
            <div class="grid content-start gap-1">
                <h2 class="text-base">Model prices</h2>
                <p class="text-sm text-muted-foreground">US dollars per million tokens. Leave a row blank to use the default price from <code class="text-xs">config/ai_pricing.php</code>. Blank cache prices default to 0.1× (read) and 1.25× (write) the input price. Saving also prices past calls recorded at $0.</p>
            </div>
            <div class="table-wrap self-start">
                <form wire:submit="savePrices">
                    @if ($prices === [])
                        <p class="px-4 py-8 text-center text-sm text-muted-foreground">Every model used so far has a default price. Add one below to override it.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Model</th>
                                        <th>Input</th>
                                        <th>Output</th>
                                        <th>Cache read</th>
                                        <th>Cache write</th>
                                        <th><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($prices as $index => $row)
                                        <tr wire:key="price-{{ $index }}" class="align-top">
                                            <td class="font-mono text-xs">
                                                <span class="flex h-8 items-center gap-1.5">
                                                    @if ($row['input'] === '' && ! config('ai_pricing.models.'.$row['model']))
                                                        <x-ui.icon name="triangle-alert" class="size-3.5 shrink-0 text-warning" title="No price: calls are counted at $0" />
                                                    @endif
                                                    {{ $row['model'] }}
                                                </span>
                                            </td>
                                            @foreach (['input', 'output', 'cache_read', 'cache_write'] as $column)
                                                <td>
                                                    <x-ui.field error="prices.{{ $index }}.{{ $column }}">
                                                        <x-ui.input wire:model="prices.{{ $index }}.{{ $column }}" inputmode="decimal" placeholder="default" class="h-8 w-24 tabular-nums" aria-label="{{ str_replace('_', ' ', $column) }} price for {{ $row['model'] }}" />
                                                    </x-ui.field>
                                                </td>
                                            @endforeach
                                            <td class="text-right">
                                                <x-ui.button variant="ghost" size="icon" class="size-8" wire:click="removeModel({{ $index }})" wire:confirm="Remove the custom price of {{ $row['model'] }}?" aria-label="Remove {{ $row['model'] }}" title="Remove">
                                                    <x-ui.icon name="trash" />
                                                </x-ui.button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <div class="table-foot flex items-start justify-end">
                        @if ($prices !== [])
                            <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="savePrices"><x-ui.icon name="save" /> Save prices</x-ui.button>
                        @endif
                    </div>
                </form>
                <form wire:submit="addModel" class="flex flex-wrap items-start gap-2 border-t p-3">
                    <x-ui.field error="newModel" class="w-64">
                        <x-ui.input wire:model="newModel" placeholder="vendor/model" class="h-8 font-mono" aria-label="Model id" />
                    </x-ui.field>
                    <x-ui.button type="submit" size="sm" variant="outline"><x-ui.icon name="plus" /> Add model</x-ui.button>
                </form>
            </div>
        </section>
    </div>

    <x-ui.dialog name="budget" title="Monthly budget" description="When this month's spend reaches it, Donkey pauses reviews, answers and learning proposals until next month. Owners get an email at 80% and 100%.">
        <form wire:submit="saveBudget" class="grid gap-4">
            <x-ui.field label="Budget per month" for="dialogBudget" error="budget" hint="Leave empty for no limit.">
                <div class="flex items-center">
                    <span class="flex h-9 items-center rounded-l-md border border-r-0 bg-muted px-3 text-sm text-muted-foreground">$</span>
                    <x-ui.input id="dialogBudget" wire:model="budget" inputmode="decimal" placeholder="No limit" class="rounded-l-none tabular-nums" />
                </div>
            </x-ui.field>
            <div class="flex justify-end gap-2">
                <x-ui.button variant="outline" x-on:click="$dispatch('close-dialog')">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveBudget"><x-ui.icon name="save" /> Save budget</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
