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
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'pin',
    ];

    protected $hidden = [
        'password',
        'pin',
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

    // --- Helpers ---

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isCashier(): bool
    {
        return $this->role === 'cashier';
    }
}
