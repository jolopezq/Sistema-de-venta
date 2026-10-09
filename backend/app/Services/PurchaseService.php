<?php

namespace App\Services;

use App\Models\FinanceExpense;
use App\Models\FinanceExpenseItem;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Support\NameKey;
use Illuminate\Support\Facades\DB;

/**
 * Compras: un gasto de Finanzas con líneas. Cada línea de insumo sube el stock y actualiza el costo promedio
 * (CPP); una línea sin insumo (gas, limpieza) es solo gasto. Editar o eliminar la compra devuelve el stock y
 * rehace el CPP, como al anular una venta. Todo ocurre en una transacción.
 */
class PurchaseService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param array<string, mixed> $data fecha, categoría, proveedor (id o nombre), pago, comprobante, notas e `items`
     */
    public function create(array $data, int $userId): FinanceExpense
    {
        return DB::transaction(function () use ($data, $userId) {
            $lines = $this->lines($data['items']);
            $supplier = $this->resolveSupplier($data['supplier_id'] ?? null, $data['supplier'] ?? null);

            $expense = FinanceExpense::create($this->header($data, $supplier, $lines) + ['user_id' => $userId]);
            $this->storeLines($expense, $lines, $userId);

            return $this->fresh($expense);
        });
    }

    /**
     * Reemplaza las líneas de la compra: devuelve lo que ingresó antes y registra las nuevas.
     *
     * @return array{0: FinanceExpense, 1: list<string>} la compra y los insumos cuyo CPP no se pudo recalcular
     */
    public function update(FinanceExpense $expense, array $data, int $userId): array
    {
        return DB::transaction(function () use ($expense, $data, $userId) {
            $lines = $this->lines($data['items']);
            $supplier = $this->resolveSupplier($data['supplier_id'] ?? null, $data['supplier'] ?? null);

            $affected = $this->reverseLines($expense, $userId, 'Compra editada');
            $expense->update($this->header($data, $supplier, $lines));
            $this->storeLines($expense, $lines, $userId);

            $affected = array_unique(array_merge($affected, array_filter(array_column($lines, 'ingredient_id'))));

            return [$this->fresh($expense), $this->recalculate($affected)];
        });
    }

    /**
     * Elimina la compra y devuelve su stock.
     *
     * @return list<string> insumos cuyo CPP no se pudo recalcular
     */
    public function delete(FinanceExpense $expense, int $userId): array
    {
        return DB::transaction(function () use ($expense, $userId) {
            $affected = $this->reverseLines($expense, $userId, 'Compra eliminada');
            $expense->delete();

            return $this->recalculate($affected);
        });
    }

    /** Proveedor por id o por nombre; un nombre nuevo lo crea (compras en mercado, sin darlo de alta antes). */
    public function resolveSupplier(?int $id, ?string $name): ?Supplier
    {
        if ($id) {
            return Supplier::findOrFail($id);
        }

        $name = NameKey::tidy($name);
        if ($name === '') {
            return null;
        }

        return Supplier::firstOrCreate(['name_key' => NameKey::make($name)], ['name' => $name]);
    }

    /**
     * Calcula cantidad en unidades de consumo, costo unitario e importe de cada línea.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(array $items): array
    {
        return array_map(function (array $item) {
            $quantity = (float) $item['quantity'];
            $factor = (float) ($item['factor'] ?? 1) ?: 1.0;
            $stockQuantity = round($quantity * $factor, 4);
            $total = round((float) $item['total'], 2);

            return [
                'ingredient_id'  => $item['ingredient_id'] ?? null,
                'description'    => isset($item['description']) ? NameKey::tidy($item['description']) ?: null : null,
                'quantity'       => $quantity,
                'purchase_unit'  => $item['purchase_unit'] ?? null,
                'factor'         => $factor,
                'stock_quantity' => $stockQuantity,
                'unit_cost'      => round($total / $stockQuantity, 4),
                'total'          => $total,
            ];
        }, array_values($items));
    }

    private function header(array $data, ?Supplier $supplier, array $lines): array
    {
        return [
            'date'                => $data['date'],
            'expense_category_id' => $data['expense_category_id'],
            'supplier'            => $supplier?->name,
            'supplier_id'         => $supplier?->id,
            'amount'              => round(array_sum(array_column($lines, 'total')), 2),
            'payment_method'      => $data['payment_method'],
            'receipt_number'      => $data['receipt_number'] ?? null,
            'notes'               => $data['notes'] ?? null,
        ];
    }

    private function storeLines(FinanceExpense $expense, array $lines, int $userId): void
    {
        $label = 'Compra' . ($expense->supplier ? " a {$expense->supplier}" : '') . " del {$expense->date->format('d/m/Y')}";

        foreach ($lines as $line) {
            $item = $expense->items()->create($line);
            if ($item->ingredient_id) {
                $this->inventory->receivePurchaseItem($item, $userId, $label);
            }
        }
    }

    /** Devuelve el stock de cada línea con insumo y borra las líneas. @return list<int> ids de insumos tocados */
    private function reverseLines(FinanceExpense $expense, int $userId, string $reason): array
    {
        $affected = [];
        foreach ($expense->items()->get() as $item) {
            if ($item->ingredient_id) {
                $this->inventory->reversePurchaseItem($item, $userId, "{$reason} (compra #{$expense->id})");
                $affected[] = $item->ingredient_id;
            }
            $item->delete();
        }

        return array_values(array_unique($affected));
    }

    /** @param list<int> $ingredientIds @return list<string> nombres de los insumos cuyo CPP no se pudo recalcular */
    private function recalculate(array $ingredientIds): array
    {
        $failed = [];
        foreach (Ingredient::whereIn('id', $ingredientIds)->get() as $ingredient) {
            if (!$this->inventory->recalculateCost($ingredient)) {
                $failed[] = $ingredient->name;
            }
        }

        return $failed;
    }

    private function fresh(FinanceExpense $expense): FinanceExpense
    {
        return $expense->fresh()->load(['category', 'user:id,name', 'items.ingredient:id,name,unit']);
    }
}
