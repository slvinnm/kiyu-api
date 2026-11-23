<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'department_id',
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
     * Whether the account operates inside a clinic tenant (any role except
     * the platform role).
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
     * Whether the account holds one of the given roles.
     */
    public function hasRole(UserRole ...$roles): bool
    {
        return $this->role !== null && in_array($this->role, $roles, true);
    }

    /**
     * Get the tenant the user belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the client profile associated with the user.
     */
    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    /**
     * Get the assigned department for the user.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the assigned station for the user.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
