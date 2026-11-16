<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return $user->id === $id;
});

Broadcast::channel('organizations.{organization}', function (User $user, Organization $organization): bool {
    return $user->belongsToOrganization($organization);
});
