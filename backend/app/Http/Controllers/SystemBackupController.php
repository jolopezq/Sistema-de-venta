<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SystemBackupController extends Controller
{
    protected BackupService $backupService;

    public function __construct(BackupService $backupService)
    {
        $this->backupService = $backupService;
    }

    /**
     * Genera y descarga un respaldo comprimido de la base de datos previa verificación de contraseña.
     */
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ], [
            'password.required' => 'Debes ingresar tu contraseña para autorizar la descarga.',
        ]);

        $user = $request->user();

        // Validar contraseña del usuario autenticado
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña de confirmación es incorrecta.',
            ], 422);
        }

        try {
            $backupInfo = $this->backupService->createCompressedSqliteBackup();

            // Registrar auditoría de acción sensible
            $formattedGzSize = round($backupInfo['gz_size'] / 1024, 2) . ' KB';
            $formattedRawSize = round($backupInfo['raw_size'] / 1024, 2) . ' KB';

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'download_backup',
                'module' => 'System',
                'description' => "Descarga de copia de seguridad atómica ({$backupInfo['filename']}, Comprimido: {$formattedGzSize}, Original: {$formattedRawSize}) desde IP: " . $request->ip(),
            ]);

            // Devolver archivo binario y borrarlo automáticamente después de enviar
            return response()->download(
                $backupInfo['gz_path'],
                $backupInfo['filename'],
                [
                    'Content-Type' => 'application/gzip',
                    'Content-Disposition' => "attachment; filename=\"{$backupInfo['filename']}\"",
                ]
            )->deleteFileAfterSend(true);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar la copia de seguridad: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sube y restaura un respaldo de base de datos comprimido en GZIP.
     */
    public function restore(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
            'backup_file' => 'required|file|mimes:gz,gzip|max:102400', // max 100MB
        ], [
            'password.required' => 'Debes ingresar tu contraseña para autorizar la restauración.',
            'backup_file.required' => 'Debes seleccionar un archivo de respaldo.',
            'backup_file.mimes' => 'El archivo debe tener formato .gz',
            'backup_file.max' => 'El archivo no puede pesar más de 100MB',
        ]);

        $user = $request->user();

        // Validar contraseña del usuario autenticado
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña de confirmación es incorrecta.',
            ], 422);
        }

        $file = $request->file('backup_file');
        
        try {
            $this->backupService->restoreCompressedSqliteBackup($file->getRealPath());

            // Registrar auditoría de acción sensible
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'restore_backup',
                'module' => 'System',
                'description' => "Restauración de base de datos desde copia de seguridad ({$file->getClientOriginalName()}) por IP: " . $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Base de datos restaurada correctamente. Recarga la página para ver los cambios.',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al restaurar la copia de seguridad: ' . $e->getMessage(),
            ], 500);
        }
    }
}
