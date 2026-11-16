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
        if (! array_key_exists($this->month, $this->months)) {
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

        $this->prices = $custom->map(fn ($price): array => ['model' => $price->model, 'input' => rtrim(rtrim($price->input_per_million, '0'), '.'), 'output' => rtrim(rtrim($price->output_per_million, '0'), '.')])
            ->concat($unpriced->map(fn (string $model): array => ['model' => $model, 'input' => '', 'output' => '']))
            ->values()
            ->all();
    }

    public function addModel(): void
    {
        $this->validate(['newModel' => ['required', 'string', 'max:255']]);

        $this->prices[] = ['model' => trim($this->newModel), 'input' => '', 'output' => ''];
        $this->newModel = '';
    }

    public function savePrices(): void
    {
        Gate::authorize('viewUsage', $this->organization);

        $this->validate([
            'prices.*.model' => ['required', 'string', 'max:255'],
            'prices.*.input' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'prices.*.output' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        foreach ($this->prices as $row) {
            if ($row['input'] === '' || $row['output'] === '') {
                $this->organization->modelPrices()->where('model', $row['model'])->delete();

                continue;
            }

            $price = $this->organization->modelPrices()->updateOrCreate(['model' => $row['model']], ['input_per_million' => $row['input'], 'output_per_million' => $row['output']]);
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
    $share = $budgetMicros ? min(1, $report['cost'] / max(1, $budgetMicros)) : null;
    $maxDay = max(1, collect($report['days'])->max('cost'));
@endphp

<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Usage & cost</h1>
            <p class="max-w-2xl text-sm text-muted-foreground">
                Estimated AI spend of {{ $this->organization->name }}, from token counts and the model prices below (defaults in <code class="text-xs">config/ai_pricing.php</code>).
                Only owners see this page.
            </p>
        </div>
        <div class="w-48">
            <x-ui.select wire:model.live="month" aria-label="Month">
                @foreach ($this->months as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    @if ($isCurrentMonth && $budgetMicros !== null && $report['cost'] >= $budgetMicros)
        <x-ui.alert variant="destructive" title="Monthly budget reached">
            Reviews, answers on GitHub and learning proposals are paused until next month. Raise the budget below to resume them now.
        </x-ui.alert>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Spent', $this->money($report['cost']), $report['previous_cost'] > 0 ? $this->money($report['previous_cost']).' the month before' : null],
            ['Projected', $report['projected'] !== null ? $this->money($report['projected']) : '—', $isCurrentMonth ? 'at this month\'s pace' : 'past month'],
            ['AI calls', number_format($report['calls']), null],
            ['Tokens', number_format($report['input_tokens'] + $report['output_tokens']), number_format($report['input_tokens']).' in · '.number_format($report['output_tokens']).' out'],
        ] as [$label, $value, $hint])
            <div class="grid content-start gap-1 rounded-xl border bg-card p-5 shadow-xs">
                <span class="text-sm text-muted-foreground">{{ $label }}</span>
                <span class="text-3xl font-semibold tabular-nums">{{ $value }}</span>
                @if ($hint)
                    <span class="text-xs text-muted-foreground">{{ $hint }}</span>
                @endif
            </div>
        @endforeach
    </div>

    <x-ui.card title="Monthly budget" description="When this month's spend reaches the budget, Donkey stops starting reviews, answering questions and proposing learnings until next month. Owners get an email at 80% and 100%.">
        <div class="grid gap-4">
            @if ($share !== null && $isCurrentMonth)
                <div class="grid gap-1.5">
                    <div class="flex justify-between text-sm">
                        <span>{{ $this->money($report['cost']) }} of {{ $this->money($budgetMicros) }}</span>
                        <span class="tabular-nums text-muted-foreground">{{ (int) round($share * 100) }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($share * 100) }}">
                        <div @class(['h-full rounded-full', 'bg-destructive' => $share >= 1, 'bg-warning' => $share >= 0.8 && $share < 1, 'bg-primary' => $share < 0.8]) style="width: {{ $share * 100 }}%"></div>
                    </div>
                </div>
            @endif

            <form wire:submit="saveBudget" class="flex flex-wrap items-start gap-2">
                <x-ui.field error="budget" class="w-48">
                    <div class="flex items-center">
                        <span class="flex h-9 items-center rounded-l-md border border-r-0 bg-muted px-3 text-sm text-muted-foreground">$</span>
                        <x-ui.input wire:model="budget" inputmode="decimal" placeholder="No limit" class="rounded-l-none" aria-label="Monthly budget in dollars" />
                    </div>
                </x-ui.field>
                <x-ui.button type="submit" variant="outline">Save budget</x-ui.button>
            </form>
        </div>
    </x-ui.card>

    <x-ui.card title="Model prices" description="US dollars per million tokens. Leave a model blank to use the default price, or none. Saving also prices past calls of that model that were recorded at $0.">
        <form wire:submit="savePrices" class="grid gap-4">
            @forelse ($prices as $index => $row)
                <div class="grid items-end gap-2 sm:grid-cols-[1fr_8rem_8rem]" wire:key="price-{{ $index }}">
                    <x-ui.field :label="$loop->first ? 'Model' : null" error="prices.{{ $index }}.model">
                        <code class="block truncate rounded-md border bg-muted/40 px-3 py-2 text-sm">{{ $row['model'] }}</code>
                    </x-ui.field>
                    <x-ui.field :label="$loop->first ? 'Input' : null" error="prices.{{ $index }}.input">
                        <x-ui.input wire:model="prices.{{ $index }}.input" inputmode="decimal" placeholder="default" aria-label="Input price for {{ $row['model'] }}" />
                    </x-ui.field>
                    <x-ui.field :label="$loop->first ? 'Output' : null" error="prices.{{ $index }}.output">
                        <x-ui.input wire:model="prices.{{ $index }}.output" inputmode="decimal" placeholder="default" aria-label="Output price for {{ $row['model'] }}" />
                    </x-ui.field>
                </div>
            @empty
                <p class="text-sm text-muted-foreground">Every model used so far has a default price. Add one below to override it.</p>
            @endforelse

            <div class="flex flex-wrap items-start gap-2">
                <x-ui.button type="submit">Save prices</x-ui.button>
            </div>
        </form>

        <form wire:submit="addModel" class="mt-4 flex flex-wrap items-start gap-2 border-t pt-4">
            <x-ui.field error="newModel" class="w-72">
                <x-ui.input wire:model="newModel" placeholder="vendor/model" aria-label="Model id" />
            </x-ui.field>
            <x-ui.button type="submit" variant="outline">Add model</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="Daily spend">
        @if ($report['cost'] === 0)
            <p class="py-6 text-center text-sm text-muted-foreground">No AI usage this month.</p>
        @else
            <div class="flex h-36 items-end gap-0.5" role="img" aria-label="Daily spend for the month">
                @foreach ($report['days'] as $day)
                    <div class="group relative flex h-full flex-1 items-end" title="{{ Carbon::parse($day['date'])->format('M j') }}: {{ $this->money($day['cost']) }}">
                        <div class="w-full rounded-t-sm bg-primary/80 group-hover:bg-primary" style="height: {{ $day['cost'] > 0 ? max(2, 100 * $day['cost'] / $maxDay) : 0 }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex justify-between text-xs text-muted-foreground">
                <span>{{ Carbon::parse($report['days'][0]['date'])->format('M j') }}</span>
                <span>{{ Carbon::parse(end($report['days'])['date'])->format('M j') }}</span>
            </div>
        @endif
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (['By feature' => $report['features'], 'By repository' => $report['repositories']] as $title => $rows)
            <x-ui.card :title="$title">
                @if ($rows === [])
                    <p class="py-4 text-center text-sm text-muted-foreground">Nothing yet.</p>
                @else
                    <ul class="grid gap-3">
                        @foreach ($rows as $row)
                            <li class="grid gap-1">
                                <div class="flex justify-between gap-4 text-sm">
                                    <span class="truncate">{{ $row['label'] }}</span>
                                    <span class="tabular-nums">{{ $this->money($row['cost']) }}</span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div class="h-full rounded-full bg-primary/70" style="width: {{ 100 * $row['cost'] / max(1, $report['cost']) }}%"></div>
                                </div>
                                <span class="text-xs text-muted-foreground">{{ number_format($row['calls']) }} {{ str('call')->plural($row['calls']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="By model">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="h-9 px-2 font-medium">Model</th>
                        <th class="h-9 px-2 text-right font-medium">Tokens in / out</th>
                        <th class="h-9 px-2 text-right font-medium">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['models'] as $row)
                        <tr class="border-b last:border-0">
                            <td class="p-2 font-mono text-xs">{{ $row['label'] }}</td>
                            <td class="p-2 text-right tabular-nums">{{ number_format($row['input_tokens']) }} / {{ number_format($row['output_tokens']) }}</td>
                            <td class="p-2 text-right tabular-nums">{{ $this->money($row['cost']) }}@unless (config('ai_pricing.models.'.$row['label'])) <span class="text-xs text-muted-foreground" title="No price configured for this model">*</span>@endunless</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="p-4 text-center text-muted-foreground">Nothing yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>

        <x-ui.card title="Most expensive pull requests">
            @if ($report['pull_requests'] === [])
                <p class="py-4 text-center text-sm text-muted-foreground">Nothing yet.</p>
            @else
                <ul class="divide-y text-sm">
                    @foreach ($report['pull_requests'] as $row)
                        <li class="flex items-center gap-3 py-2">
                            <a href="{{ route('pull-requests.show', $row['id']) }}" wire:navigate class="min-w-0 flex-1 truncate hover:underline">{{ $row['label'] }}</a>
                            <span class="tabular-nums">{{ $this->money($row['cost']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</div>
