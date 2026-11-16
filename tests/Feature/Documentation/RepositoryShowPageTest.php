<?php

use App\Models\GithubInstallation;
use App\Models\Repository;
use App\Services\Documentation\MarkdownDocument;
use Livewire\Livewire;

function repositoryWithDocument(object $organization, string $content): Repository
{
    $installation = GithubInstallation::factory()->for($organization)->create();
    $repository = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id, 'full_name' => 'acme/app']);
    $repository->document()->create(['commit_sha' => str_repeat('f', 40), 'content' => $content, 'generated_at' => now()]);

    return $repository;
}

it('builds a table of contents from the documentation headings', function () {
    $document = app(MarkdownDocument::class)->render("# Title\n\n## Overview\n\ntext\n\n### Request flow\n\n## Data model\n");

    expect($document['toc'])->toBe([
        ['level' => 2, 'id' => 'content-overview', 'title' => 'Overview'],
        ['level' => 3, 'id' => 'content-request-flow', 'title' => 'Request flow'],
        ['level' => 2, 'id' => 'content-data-model', 'title' => 'Data model'],
    ])->and($document['html'])->toContain('<h2 id="content-overview">');
});

it('shows a repository with tabs for its documentation and dependency graph', function () {
    [$user, $organization] = userInOrganization();
    $repository = repositoryWithDocument($organization, "# Architecture\n\n## Overview\n\nUseful text.\n\n## Data model\n\nOrders.");

    $this->actingAs($user)->get(route('repositories.show', $repository))
        ->assertOk()
        ->assertSee('acme/app')
        ->assertSee('Read the documentation');

    Livewire::actingAs($user)
        ->test('pages::repositories.show', ['repository' => $repository->id])
        ->set('tab', 'documentation')
        ->assertSee('On this page')
        ->assertSeeHtml('href="#content-data-model"')
        ->assertSeeHtml('<h2 id="content-overview">')
        ->set('tab', 'graph')
        ->assertSee('No code graph has been built for this repository yet.');
});

it('hides a repository from other organizations', function () {
    [, $organization] = userInOrganization();
    $repository = repositoryWithDocument($organization, '# Docs');
    [$otherUser] = userInOrganization();

    $this->actingAs($otherUser)->get(route('repositories.show', $repository))->assertNotFound();
});
