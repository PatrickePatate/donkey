<?php

use App\Enums\OrganizationRole;
use App\Models\AuditEvent;
use App\Models\Learning;
use App\Models\ReviewAgent;
use App\Models\User;
use Livewire\Livewire;

it('records approving a learning', function () {
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);
    $learning = Learning::factory()->for($organization)->proposed()->create();

    Livewire::actingAs($admin)->test('pages::knowledge.index')->call('approve', $learning->id);

    expect(AuditEvent::sole())
        ->organization_id->toBe($organization->id)
        ->user_id->toBe($admin->id)
        ->actor->toBe($admin->name)
        ->action->toBe('learning.approved')
        ->subject_id->toBe($learning->id)
        ->metadata->toBe(['body' => $learning->body]);
});

it('records creating an agent', function () {
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::agents.edit')
        ->set('name', 'Security')
        ->set('slug', 'security')
        ->set('instructions', 'Look for injection, broken authorization and leaked secrets.')
        ->call('save')
        ->assertHasNoErrors();

    $agent = ReviewAgent::sole();

    expect(AuditEvent::sole())
        ->organization_id->toBe($organization->id)
        ->action->toBe('agent.created')
        ->subject_id->toBe($agent->id)
        ->metadata->toBe(['slug' => 'security']);
});

it('records changing a member\'s role', function () {
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);
    $member = User::factory()->create();
    $organization->addMember($member, OrganizationRole::Member);

    Livewire::actingAs($owner)->test('pages::settings.members')->call('changeRole', $member->id, 'admin');

    expect(AuditEvent::sole())
        ->action->toBe('member.role_changed')
        ->subject_id->toBe($member->id)
        ->metadata->toBe(['role' => ['member', 'admin']]);
});

it('records saving organization settings with the old and new values', function () {
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);
    $organization->update(['name' => 'Acme']);

    Livewire::actingAs($owner)
        ->test('pages::settings.organization')
        ->set('name', 'Acme Robotics')
        ->set('riskThreshold', 70)
        ->call('save')
        ->assertHasNoErrors();

    expect(AuditEvent::sole())
        ->action->toBe('organization.updated')
        ->metadata->toBe(['name' => ['Acme', 'Acme Robotics'], 'risk_threshold' => [50, 70]]);
});

it('shows the audit log to admins only', function () {
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);

    foreach ([OrganizationRole::Member, OrganizationRole::Viewer] as $role) {
        $user = User::factory()->create();
        $organization->addMember($user, $role);
        $user->switchOrganization($organization);

        $this->actingAs($user)->get(route('settings.audit'))->assertForbidden();
    }

    $admin = User::factory()->create();
    $organization->addMember($admin, OrganizationRole::Admin);
    $admin->switchOrganization($organization);

    $this->actingAs($admin)->get(route('settings.audit'))->assertOk()->assertSee('Audit log');
    $this->actingAs($owner)->get(route('settings.audit'))->assertOk();
});

it('filters the audit log by action or person', function () {
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);
    AuditEvent::factory()->for($organization)->create(['action' => 'agent.created', 'actor' => 'Ada Lovelace']);
    AuditEvent::factory()->for($organization)->create(['action' => 'learning.rejected', 'actor' => 'Grace Hopper']);
    AuditEvent::factory()->create(['action' => 'agent.deleted', 'actor' => 'Someone Else']);

    Livewire::actingAs($admin)
        ->test('pages::settings.audit')
        ->assertSee('agent.created')
        ->assertSee('learning.rejected')
        ->assertDontSee('agent.deleted')
        ->set('search', 'agent')
        ->assertSee('agent.created')
        ->assertDontSee('learning.rejected')
        ->set('search', 'Grace')
        ->assertSee('learning.rejected')
        ->assertDontSee('agent.created')
        ->set('search', 'nothing-matches')
        ->assertSee('No events match');
});
