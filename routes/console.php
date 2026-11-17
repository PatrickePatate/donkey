<?php

use App\Actions\DecayLearnings;
use App\Enums\SignalType;
use App\Jobs\BuildRepositoryGraph;
use App\Jobs\ProposeLearnings;
use App\Models\FindingSignal;
use App\Models\Repository;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('learnings:propose', function () {
    $repositoryIds = FindingSignal::query()
        ->whereNull('processed_at')
        ->whereIn('type', SignalType::negativeValues())
        ->distinct()
        ->pluck('repository_id');

    Repository::query()->whereKey($repositoryIds)->each(fn (Repository $repository) => ProposeLearnings::dispatch($repository));

    $this->info("Queued learning proposals for {$repositoryIds->count()} ".str('repository')->plural($repositoryIds->count()).'.');
})->purpose('Propose learnings from feedback that has not been looked at yet');

Artisan::command('learnings:decay', function (DecayLearnings $decay) {
    $result = $decay->handle();

    $this->info("Decayed {$result['decayed']} idle ".str('learning')->plural($result['decayed']).", archived {$result['archived']}.");
})->purpose('Lower the weight of learnings that stopped matching, and archive the weakest');

Artisan::command('graphs:build {--organization= : Only the repositories of this organization id}', function () {
    $repositories = Repository::query()->when($this->option('organization'), fn ($query, $id) => $query->where('organization_id', $id));
    $count = $repositories->count();

    $repositories->each(fn (Repository $repository) => BuildRepositoryGraph::dispatch($repository));

    $this->info("Queued graph builds for {$count} ".str('repository')->plural($count).'.');
})->purpose('Build the dependency graph of every repository default branch');

Schedule::command('model:prune')->dailyAt('02:30');
Schedule::command('learnings:propose')->dailyAt('03:00');
Schedule::command('learnings:decay')->dailyAt('03:30');
