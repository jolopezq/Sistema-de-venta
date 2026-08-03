<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    /**
     * Boot the auditable trait for a model.
     */
    public static function bootAuditable()
    {
        static::created(function ($model) {
            self::logAction($model, 'created');
        });

        static::updated(function ($model) {
            self::logAction($model, 'updated');
        });

        static::deleted(function ($model) {
            self::logAction($model, 'deleted');
        });
    }

    /**
     * Log the action to the audit_logs table.
     */
    protected static function logAction($model, $action)
    {
        // Don't log if running in console (e.g. migrations/seeders) without a user
        $userId = Auth::id();
        if (!$userId && !app()->runningInConsole()) {
            return;
        }

        $module = class_basename($model);
        
        $changes = [];
        if ($action === 'updated') {
            $changes = $model->getDirty();
        } else {
            $changes = $model->getAttributes();
        }

        // Hide passwords or sensitive data from logs
        if (isset($changes['password'])) {
            $changes['password'] = '******';
        }

        $description = ucfirst($action) . " {$module} #" . $model->id . ". Cambios: " . json_encode($changes, JSON_UNESCAPED_UNICODE);

        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'description' => substr($description, 0, 5000), // Ensure we don't overflow the text column
        ]);
    }
}
