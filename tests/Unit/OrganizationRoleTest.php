<?php

use App\Enums\OrganizationRole;

it('orders roles by privilege', function () {
    expect(OrganizationRole::Owner->isAtLeast(OrganizationRole::Admin))->toBeTrue()
        ->and(OrganizationRole::Admin->isAtLeast(OrganizationRole::Admin))->toBeTrue()
        ->and(OrganizationRole::Member->isAtLeast(OrganizationRole::Admin))->toBeFalse()
        ->and(OrganizationRole::Viewer->isAtLeast(OrganizationRole::Member))->toBeFalse();
});

it('never offers owner as an assignable role', function () {
    expect(OrganizationRole::assignable())->not->toContain(OrganizationRole::Owner);
});
