<?php

namespace App\Enums;

enum UserRole: string
{
    /**
     * Platform operator. Explicitly not a tenant role: a platform account has
     * no tenant. It is granted through provisioning, never public signup.
     */
    case PlatformAdmin = 'platform_admin';

    /**
     * Tenant administrator: configuration, staff and audit.
     */
    case Admin = 'admin';

    /**
     * Front-desk operator: intake and registration queue.
     */
    case Receptionist = 'receptionist';

    /**
     * Any other internal worker (clinician, pharmacist, lab tech, cashier...).
     * Their exact duty is carried by their station assignment, not the role,
     * so the application stays usable outside a clinical setting.
     */
    case Staff = 'staff';

    /**
     * Self-service end user. May only read their own record and visits.
     */
    case Client = 'client';

    /**
     * The roles that operate inside a tenant.
     *
     * @return list<self>
     */
    public static function tenantRoles(): array
    {
        return [
            self::Admin,
            self::Receptionist,
            self::Staff,
            self::Client,
        ];
    }

    /**
     * The role values that may operate inside a tenant, for validation rules.
     *
     * @return list<string>
     */
    public static function tenantValues(): array
    {
        return array_map(fn(self $role): string => $role->value, self::tenantRoles());
    }

    /**
     * Every role value, including the platform role.
     *
     * @return list<string>
     */
    public static function allValues(): array
    {
        return array_map(fn(self $role): string => $role->value, self::cases());
    }

    /**
     * Whether this role operates inside a tenant.
     */
    public function isTenantRole(): bool
    {
        return $this !== self::PlatformAdmin;
    }

    /**
     * Whether this role is the platform operator.
     */
    public function isPlatform(): bool
    {
        return $this === self::PlatformAdmin;
    }

    /**
     * Whether this role is the tenant administrator.
     */
    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Whether this role is the self-service end user.
     */
    public function isClient(): bool
    {
        return $this === self::Client;
    }
}
