<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    /**
     * Construye la consulta base con los filtros aplicados.
     */
    private function buildQuery(Request $request)
    {
        $query = AuditLog::with('user');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Devuelve los registros de auditoría paginados.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 50);
        $logs = $this->buildQuery($request)->paginate($perPage);
        return response()->json($logs);
    }

    /**
     * Exporta los registros de auditoría a CSV.
     */
    public function export(Request $request)
    {
        $logs = $this->buildQuery($request)->get();

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=audit_logs_" . date('Y-m-d_H-i-s') . ".csv",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            
            // BOM for Excel UTF-8 support
            fputs($file, "\xEF\xBB\xBF");
            
            // Header
            fputcsv($file, ['ID', 'Fecha', 'Usuario', 'Módulo', 'Acción', 'Descripción'], ';');

            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->created_at,
                    $log->user ? $log->user->name : 'Sistema',
                    $log->module,
                    $log->action,
                    $log->description
                ], ';');
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
