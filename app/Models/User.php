<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'department_id',
        'employee_id',
        'phone',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    public function hasRole(string $code): bool
    {
        return $this->roles()->where('code', $code)->exists();
    }

    public function hasAnyRole(array $codes): bool
    {
        return $this->roles()->whereIn('code', $codes)->exists();
    }

    public function isDeptHead(): bool
    {
        return $this->hasRole('dept_head');
    }

    public function isGA(): bool
    {
        return $this->hasRole('ga');
    }

    public function isLog(): bool
    {
        return $this->hasRole('log');
    }

    public function isAccounting(): bool
    {
        return $this->hasRole('accounting');
    }

    public function isPurchasing(): bool
    {
        return $this->hasRole('purchasing');
    }

    public function isPihakI(): bool
    {
        return $this->hasAnyRole(['user', 'ga', 'log']);
    }

    public function isPihakII(): bool
    {
        return $this->hasAnyRole(['ga', 'log', 'dept_head']);
    }

    public function isPihakIII(): bool
    {
        return $this->hasRole('purchasing');
    }

    public function createdItems()
    {
        return $this->hasMany(MasterItem::class, 'created_by');
    }

    public function validatedItems()
    {
        return $this->hasMany(MasterItem::class, 'validated_by');
    }

    public function createdVendors()
    {
        return $this->hasMany(MasterVendor::class, 'created_by');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'actor_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}