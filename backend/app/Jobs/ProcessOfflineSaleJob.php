<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Repositories\SaleRepository;
use App\Services\SaleSyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessOfflineSaleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $saleData;

    /**
     * Create a new job instance.
     *
     * @param array $saleData
     */
    public function __construct(array $saleData)
    {
        $this->saleData = $saleData;
    }

    /**
     * Execute the job.
     */
    public function handle(SaleRepository $saleRepository, SaleSyncService $syncService): void
    {
        $uuid = $this->saleData['id'] ?? null;
        $cashierId = $this->saleData['cashier_id'] ?? null;

        if (!$uuid || !$cashierId) {
            Log::error('Offline sale job failed: Missing UUID or cashier ID', ['data' => $this->saleData]);
            return;
        }

        // Idempotency check
        if ($saleRepository->existsByUuid($uuid)) {
            Log::info("Sale {$uuid} already exists, skipping.");
            return;
        }

        try {
            DB::transaction(function () use ($syncService, $cashierId) {
                $syncService->processSale($this->saleData, $cashierId);
            });

            Log::info("Offline sale {$uuid} synced successfully.");
        } catch (\Exception $e) {
            Log::error("Failed to sync offline sale {$uuid}: " . $e->getMessage());
            // El framework de colas se encargará de los reintentos
            throw $e;
        }
    }
}
