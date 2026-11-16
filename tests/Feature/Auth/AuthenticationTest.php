<?php

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
