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
     * Tenant administrator and canonical owner: configuration, staff, audit,
     * billing and every facility in the tenant.
     */
    case TenantAdmin = 'tenant_admin';

    /**
     * Administrator of the facilities assigned to them. No billing, no
     * organisation ownership, no global-role escalation.
     */
    case FacilityAdmin = 'facility_admin';

    /**
     * Front-desk operator: intake and registration queue.
     */
    case Operator = 'operator';

    /**
     * Any other internal worker (agent, teller, technician, cashier...). Their
     * exact duty is carried by their station assignment, not the role, so the
     * application stays usable outside any single industry.
     */
    case Staff = 'staff';

    /**
     * Self-service end user. May only read their own record and queue data.
     * The account is linked to a `customers` row through `customers.user_id`.
     */
    case Customer = 'customer';

    /**
     * The roles that operate inside a tenant.
     *
     * @return list<self>
     */
    public static function tenantRoles(): array
    {
        return [
            self::TenantAdmin,
            self::FacilityAdmin,
            self::Operator,
            self::Staff,
            self::Customer,
        ];
    }

    /**
     * Roles that must be granted at least one facility assignment to act.
     *
     * @return list<self>
     */
    public static function facilityScopedRoles(): array
    {
        return [
            self::FacilityAdmin,
            self::Operator,
            self::Staff,
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
     * Whether the role must hold explicit facility assignments.
     */
    public function isFacilityScoped(): bool
    {
        return in_array($this, self::facilityScopedRoles(), true);
    }

    /**
     * Whether the role can administer every facility in its tenant without an
     * explicit assignment.
     */
    public function hasTenantWideAccess(): bool
    {
        return $this === self::TenantAdmin;
    }

    /**
     * The roles allowed to authenticate into a given application context.
     *
     * Public and webhook traffic is unauthenticated; only the four authenticated
     * contexts appear here. TenantAdmin is deliberately absent from the Tenant
     * workspace: it manages its organization from the App context instead.
     *
     * @return list<self>
     */
    public static function forContext(ApplicationContext $context): array
    {
        return match ($context) {
            ApplicationContext::Public => [],
            ApplicationContext::Customer => [self::Customer],
            ApplicationContext::App => [self::TenantAdmin],
            ApplicationContext::Tenant => [self::FacilityAdmin, self::Operator, self::Staff],
            ApplicationContext::Portal => [self::PlatformAdmin],
        };
    }

    /**
     * Whether this role may sign in to the given context.
     */
    public function allowedIn(ApplicationContext $context): bool
    {
        return in_array($this, self::forContext($context), true);
    }
}
