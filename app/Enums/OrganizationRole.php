<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full access, including billing and deleting the organization.',
            self::Admin => 'Manage members, GitHub connection, repositories and settings.',
            self::Member => 'Configure repositories, trigger reviews and add memories.',
            self::Viewer => 'Read-only access to reviews and settings.',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Member => 20,
            self::Viewer => 10,
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    /**
     * Roles that can be granted through an invitation or role change.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Member, self::Viewer];
    }
}
