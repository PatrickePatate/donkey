<?php

use App\Models\Invitation;
use App\Models\User;
use Livewire\Livewire;

it('registers a user and sends them to create an organization', function () {
    Livewire::test('pages::auth.register')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'correct-horse-battery')
        ->call('register')
        ->assertRedirect(route('verification.notice'));

    $this->assertAuthenticated();
    expect(User::where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('logs a user in with valid credentials', function () {
    $user = User::factory()->create();

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create();

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('logs out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('closes the sign-up page when registration is disabled', function () {
    config(['app.registration_enabled' => false]);

    $this->get(route('register'))->assertNotFound();
    $this->get(route('login'))->assertOk()->assertDontSee('No account yet?');
});

it('still lets an invited person register when registration is disabled', function () {
    config(['app.registration_enabled' => false]);
    $invitation = Invitation::factory()->create(['email' => 'invited@example.com']);

    $this->get(route('register', ['email' => $invitation->email]))->assertOk();

    Livewire::withQueryParams(['email' => $invitation->email])
        ->test('pages::auth.register')
        ->set('name', 'Invited')
        ->set('email', 'invited@example.com')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'correct-horse-battery')
        ->call('register')
        ->assertRedirect(route('verification.notice'));
});

it('rejects registration for an email without an invitation when registration is disabled', function () {
    config(['app.registration_enabled' => false]);

    Livewire::test('pages::auth.register')->assertNotFound();
});
