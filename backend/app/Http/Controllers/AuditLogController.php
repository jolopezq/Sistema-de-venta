<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    /**
     * Devuelve todos los registros de auditoría.
     */
    public function index(): JsonResponse
    {
        return response()->json(AuditLog::with('user')->orderBy('created_at', 'desc')->get());
    }
}
