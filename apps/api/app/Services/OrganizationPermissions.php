<?php

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;

class OrganizationPermissions
{
    /** @var array<string, array{label: string, description: string}> */
    private const CATALOG = [
        'organization.memberships.read' => [
            'label' => 'View members',
            'description' => 'View organization membership records and their status.',
        ],
        'organization.memberships.manage' => [
            'label' => 'Manage members',
            'description' => 'Change eligible member roles and suspend or restore membership access.',
        ],
        'organization.memberships.audit' => [
            'label' => 'View access audit',
            'description' => 'View membership role, status, and permission change history.',
        ],
    ];

    public function catalog(): array
    {
        return collect(self::CATALOG)->map(fn (array $item, string $key): array => [
            'key' => $key,
            ...$item,
        ])->values()->all();
    }

    public function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    public function isPrivilegedRole(OrganizationRole $role): bool
    {
        return in_array($role, [OrganizationRole::Admin, OrganizationRole::IndependentEducator], true);
    }

    public function isGrantorRole(OrganizationRole $role): bool
    {
        return $this->isPrivilegedRole($role);
    }

    public function canAssignRole(OrganizationRole $currentRole, OrganizationRole $nextRole): bool
    {
        if ($nextRole === OrganizationRole::Parent && $currentRole !== OrganizationRole::Parent) {
            return false;
        }

        $rank = static fn (OrganizationRole $role): int => match ($role) {
            OrganizationRole::Admin, OrganizationRole::IndependentEducator => 100,
            OrganizationRole::Management => 50,
            OrganizationRole::Teacher => 30,
            OrganizationRole::Student, OrganizationRole::Parent => 10,
        };

        return $rank($nextRole) <= $rank($currentRole);
    }

    public function explicit(OrganizationMembership $membership): array
    {
        $overrides = $membership->relationLoaded('permissionOverrides')
            ? $membership->permissionOverrides
            : $membership->permissionOverrides()->get();

        return $overrides->pluck('permission_key')->sort()->values()->all();
    }

    public function effective(OrganizationMembership $membership): array
    {
        if ($this->isPrivilegedRole($membership->role)) {
            return $this->keys();
        }

        $defaults = $membership->role === OrganizationRole::Management
            ? ['organization.memberships.read']
            : [];

        return array_values(array_unique([...$defaults, ...$this->explicit($membership)]));
    }

    public function allows(OrganizationMembership $membership, string $permission): bool
    {
        return in_array($permission, $this->effective($membership), true);
    }
}
