<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use App\Models\Product;
use Carbon\Carbon;

#[Signature('products:reactivate')]
#[Description('Reactivates products that have reached their scheduled reactivation time')]
class ReactivateProducts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = Product::where('is_active', false)
            ->whereNotNull('reactivate_at')
            ->where('reactivate_at', '<=', Carbon::now())
            ->update([
                'is_active' => true,
                'reactivate_at' => null,
            ]);

        $this->info("Reactivated {$count} products.");
    }
}
