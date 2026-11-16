<?php

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('lets admins invite members by email', function () {
    Mail::fake();
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::settings.members')
        ->set('inviteEmail', 'New.Person@example.com')
        ->set('inviteRole', 'member')
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = $organization->invitations()->sole();

    expect($invitation->email)->toBe('new.person@example.com')
        ->and($invitation->role)->toBe(OrganizationRole::Member);

    Mail::assertQueued(OrganizationInvitation::class, fn ($mail) => $mail->hasTo('new.person@example.com'));
});

it('does not allow inviting someone as owner', function () {
    Mail::fake();
    [$owner] = userInOrganization();

    Livewire::actingAs($owner)
        ->test('pages::settings.members')
        ->set('inviteEmail', 'someone@example.com')
        ->set('inviteRole', 'owner')
        ->call('invite')
        ->assertHasErrors('inviteRole');
});

it('forbids members from inviting', function () {
    [$member] = userInOrganization(OrganizationRole::Member);

    Livewire::actingAs($member)
        ->test('pages::settings.members')
        ->set('inviteEmail', 'someone@example.com')
        ->call('invite')
        ->assertForbidden();
});

it('accepts an invitation addressed to the signed-in user', function () {
    $invitation = Invitation::factory()->create(['role' => OrganizationRole::Viewer]);
    $user = User::factory()->create(['email' => $invitation->email]);

    Livewire::actingAs($user)
        ->test('pages::invitations.accept', ['token' => $invitation->token])
        ->call('accept')
        ->assertRedirect(route('dashboard'));

    expect($invitation->organization->roleOf($user))->toBe(OrganizationRole::Viewer)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($user->fresh()->current_organization_id)->toBe($invitation->organization_id);
});

it('refuses invitations addressed to someone else', function () {
    $invitation = Invitation::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::invitations.accept', ['token' => $invitation->token])
        ->call('accept')
        ->assertHasErrors('invitation');

    expect($user->belongsToOrganization($invitation->organization))->toBeFalse();
});

it('refuses expired invitations', function () {
    $invitation = Invitation::factory()->expired()->create();
    $user = User::factory()->create(['email' => $invitation->email]);

    Livewire::actingAs($user)
        ->test('pages::invitations.accept', ['token' => $invitation->token])
        ->call('accept')
        ->assertHasErrors('invitation');
});

it('lets owners change roles', function () {
    [$owner, $organization] = userInOrganization();
    $member = User::factory()->create();
    $organization->addMember($member, OrganizationRole::Member);

    Livewire::actingAs($owner)
        ->test('pages::settings.members')
        ->call('changeRole', $member->id, 'admin');

    expect($organization->roleOf($member))->toBe(OrganizationRole::Admin);
});

it('stops admins from changing an owner role', function () {
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);
    $owner = User::factory()->create();
    $organization->addMember($owner, OrganizationRole::Owner);

    Livewire::actingAs($admin)
        ->test('pages::settings.members')
        ->call('changeRole', $owner->id, 'viewer');

    expect($organization->roleOf($owner))->toBe(OrganizationRole::Owner);
});

it('keeps at least one owner', function () {
    [$owner, $organization] = userInOrganization();
    $organization->addMember(User::factory()->create(), OrganizationRole::Member);

    Livewire::actingAs($owner)
        ->test('pages::settings.members')
        ->call('removeMember', $owner->id);

    expect($organization->roleOf($owner))->toBe(OrganizationRole::Owner);
});

it('lets admins remove members', function () {
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);
    $member = User::factory()->create();
    $organization->addMember($member, OrganizationRole::Member);

    Livewire::actingAs($admin)
        ->test('pages::settings.members')
        ->call('removeMember', $member->id);

    expect($member->belongsToOrganization($organization))->toBeFalse();
});
