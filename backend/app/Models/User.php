<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Modelo de usuario del sistema (Administrador o Cajero).
 * Utiliza SoftDeletes para nunca eliminar registros de forma permanente.
 * Utiliza Sanctum para autenticación SPA-API con tokens sin estado.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, \App\Traits\Auditable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'ci',
        'phone',
        'start_date',
        'photo_url',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    // --- Relaciones ---

    /**
     * Ventas atendidas por este usuario (como cajero).
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'cashier_id');
    }

    /**
     * Sesiones de caja operadas por este usuario.
     */
    public function cashRegisterSessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class, 'cashier_id');
    }

    /**
     * Movimientos de inventario realizados por este usuario.
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'performed_by');
    }

    /** Datos laborales (asistencia y sueldo). Solo para administradores. */
    public function employeeProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    /** Marcas de entrada y salida de esta persona. */
    public function attendanceMarks(): HasMany
    {
        return $this->hasMany(AttendanceMark::class);
    }

    /** Capturas del rostro registrado. */
    public function faceEnrollments(): HasMany
    {
        return $this->hasMany(FaceEnrollment::class);
    }

    // --- Helpers ---

    /** Excepciones de permisos de esta persona (ver PermissionService). */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->role === 'super_admin';
    }

    public function isCashier(): bool
    {
        return $this->role === 'cashier';
    }

    public function isKitchen(): bool
    {
        return $this->role === 'kitchen';
    }

    /** «Personal»: solo marca asistencia, no inicia sesión (docs/PLAN_ASISTENCIA_Y_SUELDOS.md, D2). */
    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }
}
