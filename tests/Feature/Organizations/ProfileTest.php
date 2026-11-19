<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('lets a user upload and remove their avatar', function () {
    Storage::fake('public');
    [$user] = userInOrganization();

    Livewire::actingAs($user)->test('pages::settings.profile')->set('avatar', UploadedFile::fake()->image('me.png'))->assertHasNoErrors();
    $path = $user->fresh()->avatar_path;
    Storage::disk('public')->assertExists($path);

    Livewire::actingAs($user)->test('pages::settings.profile')->call('removeAvatar');
    Storage::disk('public')->assertMissing($path);
    expect($user->fresh()->avatar_path)->toBeNull();
});

it('saves the GitHub username lowercased and refuses invalid ones', function () {
    [$user] = userInOrganization();

    Livewire::actingAs($user)->test('pages::settings.profile')->set('githubLogin', 'bad login!')->call('save')->assertHasErrors('githubLogin');
    Livewire::actingAs($user)->test('pages::settings.profile')->set('githubLogin', '@Octo-Cat')->call('save')->assertHasNoErrors();

    expect($user->fresh()->github_login)->toBe('octo-cat');
});

it('refuses a GitHub username another member already linked', function () {
    [$user] = userInOrganization();
    [$other] = userInOrganization();
    $other->update(['github_login' => 'octocat']);

    Livewire::actingAs($user)->test('pages::settings.profile')->set('githubLogin', '@OctoCat')->call('save')->assertHasErrors(['githubLogin' => 'unique']);
    Livewire::actingAs($other)->test('pages::settings.profile')->set('githubLogin', 'octocat')->call('save')->assertHasNoErrors();
});

it('matches a GitHub login to a member of the same organization only', function () {
    [$user, $organization] = userInOrganization();
    $user->update(['github_login' => 'octocat']);
    [$outsider] = userInOrganization();
    $outsider->update(['github_login' => 'hubot']);

    expect($organization->memberByGithubLogin('OctoCat')?->is($user))->toBeTrue()
        ->and($organization->memberByGithubLogin('hubot'))->toBeNull()
        ->and($organization->memberByGithubLogin(null))->toBeNull();
});
