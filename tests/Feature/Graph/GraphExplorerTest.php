<?php

use App\Enums\GraphStatus;
use App\Models\GithubInstallation;
use App\Models\Repository;
use App\Models\RepositoryGraph;
use App\Services\Graph\GraphExplorer;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

function explorerGraph(Repository $repository): RepositoryGraph
{
    $node = fn (string $kind, string $file, string $name, ?string $parent = null, bool $test = false): array => [
        'kind' => $kind,
        'name' => $name,
        'qualified_name' => $parent ? "{$file}::{$parent}.{$name}" : "{$file}::{$name}",
        'file_path' => $file,
        'line_start' => 10,
        'parent_name' => $parent,
        'is_test' => $test,
    ];

    $data = [
        'metadata' => ['total_nodes' => 6, 'total_edges' => 5],
        'nodes' => [
            $node('Class', 'app/Http/Controllers/OrderController.php', 'OrderController'),
            $node('Method', 'app/Http/Controllers/OrderController.php', 'store', 'OrderController'),
            $node('Class', 'app/Services/Billing.php', 'Billing'),
            $node('Method', 'app/Services/Billing.php', 'charge', 'Billing'),
            $node('Class', 'app/Models/Order.php', 'Order'),
            $node('Test', 'tests/Feature/BillingTest.php', 'charges orders', test: true),
        ],
        'edges' => [
            ['kind' => 'CALLS', 'source_qualified' => 'app/Http/Controllers/OrderController.php::OrderController.store', 'target_qualified' => 'app/Services/Billing.php::Billing.charge'],
            ['kind' => 'USES_TYPE', 'source_qualified' => 'app/Services/Billing.php::Billing.charge', 'target_qualified' => 'app/Models/Order.php::Order'],
            ['kind' => 'CONTAINS', 'source_qualified' => 'app/Services/Billing.php::Billing', 'target_qualified' => 'app/Services/Billing.php::Billing.charge'],
            ['kind' => 'TESTED_BY', 'source_qualified' => 'app/Services/Billing.php::Billing.charge', 'target_qualified' => 'tests/Feature/BillingTest.php::charges orders'],
            ['kind' => 'CALLS', 'source_qualified' => 'app/Services/Billing.php::Billing.charge', 'target_qualified' => 'vendor/Missing.php::Missing.run'],
        ],
    ];

    $path = storage_path('framework/testing/graphs/'.$repository->id.'.json');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode($data));

    return $repository->graphs()->create([
        'commit_sha' => str_repeat('a', 40),
        'status' => GraphStatus::Ready,
        'path' => $path,
        'built_at' => now(),
    ]);
}

function explorerRepository(): array
{
    [$user, $organization] = userInOrganization();
    $installation = GithubInstallation::factory()->for($organization)->create();
    $repository = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id]);

    return [$user, $repository];
}

it('categorises nodes into modules, classes, services, models, endpoints, methods and tests', function () {
    [, $repository] = explorerRepository();
    $explorer = app(GraphExplorer::class);
    explorerGraph($repository);
    $graph = $explorer->latestGraph($repository);

    expect($explorer->categoryCounts($graph))->toBe([
        'module' => 4,
        'class' => 0,
        'service' => 1,
        'model' => 1,
        'endpoint' => 2,
        'method' => 1,
        'test' => 1,
    ]);
});

it('navigates from a method to its callers, dependencies and tests', function () {
    [, $repository] = explorerRepository();
    $explorer = app(GraphExplorer::class);
    $graph = explorerGraph($repository);

    $hood = $explorer->neighbourhood($graph, 'app/Services/Billing.php::Billing.charge');

    expect(array_column(array_column($hood['callers'], 'node'), 'label'))->toBe(['OrderController::store()'])
        ->and(array_column(array_column($hood['dependencies'], 'node'), 'label'))->toBe(['Order'])
        ->and(array_column($hood['tests'], 'label'))->toBe(['charges orders'])
        ->and(array_column($explorer->neighbourhood($graph, 'app/Services/Billing.php::Billing')['members'], 'label'))->toBe(['Billing::charge()'])
        ->and($explorer->neighbourhood($graph, 'unknown'))->toBeNull();
});

it('renders the explorer and lets developers search and focus nodes', function () {
    [$user, $repository] = explorerRepository();
    explorerGraph($repository);

    Livewire::actingAs($user)
        ->test('pages::repositories.graph', ['repository' => $repository->id])
        ->set('search', 'charge')
        ->assertSee('Billing::charge()')
        ->assertDontSee('OrderController::store()')
        ->call('select', 'app/Services/Billing.php::Billing.charge')
        ->assertSee('Callers (1)')
        ->assertSee('Dependencies (1)')
        ->assertSee('OrderController::store()')
        ->assertSee('charges orders');
});

it('shows an empty state without a graph and hides other organizations repositories', function () {
    [$user, $repository] = explorerRepository();

    $this->actingAs($user)
        ->get(route('repositories.graph', $repository))
        ->assertOk()
        ->assertSee('No graph yet');

    [$otherUser] = userInOrganization();

    $this->actingAs($otherUser)
        ->get(route('repositories.graph', $repository))
        ->assertNotFound();
});
