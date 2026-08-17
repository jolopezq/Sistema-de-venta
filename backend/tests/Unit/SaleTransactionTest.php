<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Sale;
use App\Models\User;
use App\Repositories\SaleRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;

class SaleTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected $saleRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saleRepository = new SaleRepository();
    }

    public function test_idempotency_prevents_duplicate_sales()
    {
        $user = User::factory()->create();
        $uuid = Str::uuid()->toString();

        DB::table('sales')->insert([
            'id' => $uuid,
            'cashier_id' => $user->id,
            'subtotal' => 100,
            'discount_amount' => 0,
            'total_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exists = $this->saleRepository->existsByUuid($uuid);
        
        $this->assertTrue($exists, 'Sale should exist to prevent duplication');
    }

    public function test_sale_creation_is_transactional()
    {
        $user = User::factory()->create();
        $uuid = Str::uuid()->toString();
        
        try {
            DB::transaction(function () use ($uuid, $user) {
                $this->saleRepository->create([
                    'id' => $uuid,
                    'cashier_id' => $user->id,
                    'subtotal' => 100,
                    'discount_amount' => 0,
                    'total_amount' => 100,
                ]);

                // Simulamos un error para asegurar el rollback
                throw new Exception('Simulated failure during inventory deduction');
            });
        } catch (Exception $e) {
            // Expected exception
        }

        $this->assertDatabaseMissing('sales', [
            'id' => $uuid
        ]);
    }
}
