<?php

use App\Enums\OrganizationRole;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

function verificationUrl(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
}

it('joins the matching organization when the email is verified', function () {
    $organization = Organization::factory()->create(['auto_join_domains' => ['acme.test'], 'auto_join_role' => OrganizationRole::Viewer]);
    $user = User::factory()->unverified()->create(['email' => 'grace@Acme.test']);

    $this->actingAs($user)
        ->get(verificationUrl($user))
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->roleIn($organization))->toBe(OrganizationRole::Viewer)
        ->and($user->current_organization_id)->toBe($organization->id)
        ->and(AuditEvent::sole()->action)->toBe('member.auto_joined');
});

it('does not join organizations while the email is unverified', function () {
    $organization = Organization::factory()->create(['auto_join_domains' => ['acme.test']]);
    $user = User::factory()->unverified()->create(['email' => 'grace@acme.test']);

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    expect($user->fresh()->belongsToOrganization($organization))->toBeFalse();
});

it('joins on sign-in an organization whose domain was added later', function () {
    [$user, $existing] = userInOrganization(OrganizationRole::Member);
    $user->update(['email' => 'grace@acme.test']);
    $organization = Organization::factory()->create();
    $organization->update(['auto_join_domains' => ['acme.test']]);

    Livewire::test('pages::auth.login')
        ->set('email', 'grace@acme.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->roleIn($organization))->toBe(OrganizationRole::Member)
        ->and($user->current_organization_id)->toBe($existing->id);
});

it('never matches public email providers', function () {
    Organization::factory()->create(['auto_join_domains' => ['gmail.com']]);
    $user = User::factory()->unverified()->create(['email' => 'grace@gmail.com']);

    $this->actingAs($user)->get(verificationUrl($user));

    expect($user->fresh()->organizations()->count())->toBe(0);
});

it('validates auto-join domains in the organization settings', function (string $domains, string $error) {
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);
    $admin->update(['email' => 'ada@acme.test']);
    Organization::factory()->create(['auto_join_domains' => ['claimed.test']]);

    Livewire::actingAs($admin)
        ->test('pages::settings.organization')
        ->set('autoJoinDomains', $domains)
        ->call('save')
        ->assertHasErrors('autoJoinDomains')
        ->assertSee($error);

    expect($organization->fresh()->auto_join_domains)->toBeNull();
})->with([
    'invalid domain' => ['not a domain!', 'is not a valid domain'],
    'public provider' => ['gmail.com', 'public email provider'],
    'claimed by another organization' => ['claimed.test', 'already used by another organization'],
    'not the admin\'s own domain' => ['other.test', 'sign in with a verified other.test email address'],
]);

it('saves the admin\'s own domain, keeps existing ones, and audits the change', function () {
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);
    $admin->update(['email' => 'ada@acme.test']);
    $organization->update(['auto_join_domains' => ['legacy.test']]);

    Livewire::actingAs($admin)
        ->test('pages::settings.organization')
        ->assertSet('autoJoinDomains', 'legacy.test')
        ->set('autoJoinDomains', "legacy.test\n@ACME.test")
        ->set('autoJoinRole', 'viewer')
        ->call('save')
        ->assertHasNoErrors();

    $organization->refresh();

    expect($organization->auto_join_domains)->toBe(['legacy.test', 'acme.test'])
        ->and($organization->auto_join_role)->toBe(OrganizationRole::Viewer)
        ->and(AuditEvent::sole())
        ->action->toBe('organization.updated')
        ->user_id->toBe($admin->id);
});

it('sends new users to the verification page', function () {
    Livewire::test('pages::auth.register')
        ->set('name', 'Grace Hopper')
        ->set('email', 'grace@acme.test')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'correct-horse-battery')
        ->call('register')
        ->assertRedirect(route('verification.notice'));
});

it('lists the organizations the user will join on the verification page', function () {
    Organization::factory()->create(['name' => 'Acme Robotics', 'auto_join_domains' => ['acme.test']]);
    $user = User::factory()->unverified()->create(['email' => 'grace@acme.test']);

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertSee('you will join Acme Robotics automatically');
});

it('does not join an organization that turned auto-join off', function () {
    $organization = Organization::factory()->create(['auto_join_domains' => ['acme.test'], 'auto_join_enabled' => false]);
    $user = User::factory()->unverified()->create(['email' => 'grace@acme.test']);

    $this->actingAs($user)->get(verificationUrl($user));

    expect($user->fresh()->belongsToOrganization($organization))->toBeFalse();
});
