<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'station_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'tenant_id' => 'integer',
        ];
    }

    /**
     * Whether the account operates inside a tenant (any role except the
     * platform role).
     */
    public function isTenantUser(): bool
    {
        return $this->role?->isTenantRole() ?? false;
    }

    /**
     * Whether the account is the platform operator.
     */
    public function isPlatformUser(): bool
    {
        return $this->role?->isPlatform() ?? false;
    }

    /**
     * Whether the account has implicit access to every facility in its tenant.
     */
    public function hasTenantWideAccess(): bool
    {
        return $this->role?->hasTenantWideAccess() ?? false;
    }

    /**
     * Whether the account administers the whole tenant.
     */
    public function isTenantAdmin(): bool
    {
        return $this->role === UserRole::TenantAdmin;
    }

    /**
     * Whether the account may act at the given facility. A tenant admin has
     * implicit access to every facility of the tenant; a facility-scoped role
     * needs an explicit assignment.
     */
    public function canAccessFacility(int $facilityId): bool
    {
        if (! $this->isTenantUser()) {
            return false;
        }

        if ($this->isTenantAdmin()) {
            return Facility::query()
                ->whereKey($facilityId)
                ->where('tenant_id', $this->tenant_id)
                ->exists();
        }

        return $this->facilityAssignments()
            ->where('facility_id', $facilityId)
            ->where('tenant_id', $this->tenant_id)
            ->exists();
    }

    /**
     * Get the tenant the user belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the customer profile linked to the account, if any.
     */
    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    /**
     * Get the optional default station for this user.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the explicit facility assignments for this user.
     */
    public function facilityAssignments(): HasMany
    {
        return $this->hasMany(UserFacilityAssignment::class);
    }

    /**
     * Get the facilities this user is explicitly assigned to.
     */
    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'user_facility_assignments')
            ->withTimestamps();
    }
}
