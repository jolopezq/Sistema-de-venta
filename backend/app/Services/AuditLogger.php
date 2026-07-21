<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * Log a sensitive action to the audit_logs table.
     *
     * @param string $action Example: 'void_sale', 'register_waste', 'create_user'
     * @param string $module Example: 'sales', 'inventory', 'users'
     * @param string|null $description Optional details about the action.
     * @param int|null $userId Optional user ID if different from current authenticated user.
     */
    public static function log(string $action, string $module, ?string $description = null, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();

        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'description' => $description,
        ]);
    }
}
