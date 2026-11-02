<?php

namespace App\Services;

use App\Models\User;

/**
 * Reglas del descuento manual (docs/PLAN_DESCUENTOS_CAJA.md).
 *
 * - Solo quien tiene el permiso por persona «Descuentos en caja» (`pos_discounts`). El Super Admin, siempre.
 * - Tope por rol sobre el subtotal: cajero 20 %, administrador y Super Admin 100 % (cortesía).
 * - Motivo de texto libre obligatorio, de 10 a 255 caracteres.
 * - Solo en ventas del local: PedidosYa no lleva descuento manual.
 *
 * El POS recibe la política del usuario en /login y /me (`discount_policy`), así el tope está escrito solo aquí.
 */
class DiscountPolicy
{
    public const MODULE = 'pos_discounts';

    /** Tope del descuento por rol, en % del subtotal. */
    public const MAX_PERCENT = [
        'cashier'     => 20,
        'admin'       => 100,
        'super_admin' => 100,
    ];

    public const REASON_MIN = 10;
    public const REASON_MAX = 255;

    public function __construct(private readonly PermissionService $permissions)
    {
    }

    /**
     * Lo que el POS necesita saber del usuario.
     *
     * @return array{allowed: bool, max_percent: int, reason_min: int, reason_max: int}
     */
    public function for(User $user): array
    {
        return [
            'allowed'     => $this->allowed($user),
            'max_percent' => $this->maxPercent($user),
            'reason_min'  => self::REASON_MIN,
            'reason_max'  => self::REASON_MAX,
        ];
    }

    public function allowed(User $user): bool
    {
        return $this->permissions->can($user, self::MODULE, 'edit');
    }

    public function maxPercent(User $user): int
    {
        return self::MAX_PERCENT[$user->role] ?? 0;
    }

    /**
     * Monto máximo para ese subtotal: el % del rol, redondeado hacia abajo al centavo.
     */
    public static function maxAmountFor(int $maxPercent, float $subtotal): float
    {
        $cents = (int) round($subtotal * 100);
        if ($maxPercent >= 100) {
            return $cents / 100;
        }

        return intdiv($cents * $maxPercent, 100) / 100;
    }

    public function maxAmount(User $user, float $subtotal): float
    {
        return self::maxAmountFor($this->maxPercent($user), $subtotal);
    }

    /**
     * Infracciones de un descuento (vacío si cumple todas las reglas o si no hay descuento).
     *
     * @return string[]
     */
    public function review(User $user, float $subtotal, float $discount, ?string $reason, string $source = 'pos'): array
    {
        if ($discount <= 0) {
            return [];
        }

        $issues = [];
        $percent = self::percent($discount, $subtotal);

        if ($source === 'pedidosya') {
            $issues[] = 'descuento manual en un pedido de PedidosYa';
        }
        if (!$this->allowed($user)) {
            $issues[] = "{$user->name} no tiene el permiso «Descuentos en caja»";
        }
        $max = $this->maxAmount($user, $subtotal);
        if ($discount - $max > 0.004) {
            $issues[] = sprintf(
                'supera el %d %% permitido a su rol (%s %%, máximo Bs %s)',
                $this->maxPercent($user),
                self::formatPercent($percent),
                number_format($max, 2, ',', '.')
            );
        }
        $length = mb_strlen(trim((string) $reason));
        if ($length < self::REASON_MIN) {
            $issues[] = $length === 0 ? 'sin motivo' : "motivo de {$length} caracteres (mínimo " . self::REASON_MIN . ')';
        }

        return $issues;
    }

    public static function percent(float $discount, float $subtotal): float
    {
        return $subtotal > 0 ? round($discount / $subtotal * 100, 1) : 0.0;
    }

    public static function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, ',', ''), '0'), ',');
    }

    /**
     * Texto de auditoría: «descuento Bs 20,00 (13,3 %) sobre Bs 150,00» o «cortesía (100 %) de Bs 45,00».
     */
    public static function describe(float $discount, float $subtotal): string
    {
        $bs = fn (float $n) => 'Bs ' . number_format($n, 2, ',', '.');
        if ($subtotal > 0 && $discount >= $subtotal - 0.004) {
            return 'cortesía (100 %) de ' . $bs($subtotal);
        }

        return sprintf('descuento %s (%s %%) sobre %s', $bs($discount), self::formatPercent(self::percent($discount, $subtotal)), $bs($subtotal));
    }
}
