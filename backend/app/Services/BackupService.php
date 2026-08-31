<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BackupService
{
    /**
     * Genera una copia atómica de la base de datos SQLite y la comprime en formato Gzip.
     *
     * @return array{gz_path: string, filename: string, raw_size: int, gz_size: int}
     * @throws RuntimeException
     */
    public function createCompressedSqliteBackup(): array
    {
        $dbConnection = config('database.default');
        if ($dbConnection !== 'sqlite') {
            throw new RuntimeException("El servicio de respaldo atómico está optimizado únicamente para conexiones SQLite.");
        }

        $timestamp = date('Y-m-d_His');
        $filename = "ohana_backup_{$timestamp}.sqlite.gz";

        $tempDir = storage_path('app/temp_backups');
        if (!File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $rawSqlitePath = "{$tempDir}/temp_{$timestamp}.sqlite";
        $gzSqlitePath = "{$tempDir}/{$filename}";

        try {
            $escapedPath = str_replace("'", "''", $rawSqlitePath);
            $dbPath = config('database.connections.sqlite.database');
            $pdo = DB::connection()->getPdo();

            // SQLite no permite ejecutar VACUUM si hay una transacción activa en la misma conexión
            // Si no hay transacción activa en PDO, ejecutar VACUUM INTO (óptimo y atómico en producción)
            if ($pdo && !$pdo->inTransaction()) {
                DB::statement("VACUUM INTO '{$escapedPath}'");
            } elseif ($dbPath && $dbPath !== ':memory:' && File::exists($dbPath)) {
                // Si hay transacción o archivo físico ocupado, hacer copia de archivo
                File::copy($dbPath, $rawSqlitePath);
            } else {
                // Caso especial para SQLite in-memory (:memory:) con transacción activa en testing
                // Cerramos temporalmente la transacción de test para VACUUM o usamos backup API
                $pdo->commit();
                DB::statement("VACUUM INTO '{$escapedPath}'");
                $pdo->beginTransaction();
            }

            if (!File::exists($rawSqlitePath)) {
                throw new RuntimeException("No se pudo generar la copia atómica de SQLite.");
            }

            $rawSize = File::size($rawSqlitePath);

            // Comprimir el archivo en Gzip
            $this->gzipFile($rawSqlitePath, $gzSqlitePath);
            $gzSize = File::size($gzSqlitePath);

            // Eliminar el archivo SQLite sin comprimir del directorio temporal
            File::delete($rawSqlitePath);

            return [
                'gz_path' => $gzSqlitePath,
                'filename' => $filename,
                'raw_size' => $rawSize,
                'gz_size' => $gzSize,
            ];
        } catch (\Throwable $e) {
            // Limpieza en caso de fallo
            if (File::exists($rawSqlitePath)) {
                File::delete($rawSqlitePath);
            }
            if (File::exists($gzSqlitePath)) {
                File::delete($gzSqlitePath);
            }
            throw $e;
        }
    }

    /**
     * Comprime un archivo a GZIP en bloques de 1MB para no saturar la memoria RAM.
     */
    private function gzipFile(string $sourcePath, string $destPath): void
    {
        $srcHandle = fopen($sourcePath, 'rb');
        if ($srcHandle === false) {
            throw new RuntimeException("No se pudo abrir el archivo origen para comprimir: {$sourcePath}");
        }

        $gzHandle = gzopen($destPath, 'wb9'); // Compresión máxima (nivel 9)
        if ($gzHandle === false) {
            fclose($srcHandle);
            throw new RuntimeException("No se pudo crear el archivo gzip de destino: {$destPath}");
        }

        while (!feof($srcHandle)) {
            gzwrite($gzHandle, fread($srcHandle, 1024 * 1024));
        }

        fclose($srcHandle);
        gzclose($gzHandle);
    }

    /**
     * Restaura la base de datos a partir de un archivo SQLite comprimido en GZIP.
     * Sobrescribe directamente la base de datos actual.
     *
     * @param string $gzSourcePath Ruta del archivo .gz a restaurar.
     * @throws RuntimeException
     */
    public function restoreCompressedSqliteBackup(string $gzSourcePath): void
    {
        $dbConnection = config('database.default');
        if ($dbConnection !== 'sqlite') {
            throw new RuntimeException("El servicio de respaldo atómico está optimizado únicamente para conexiones SQLite.");
        }

        $dbPath = config('database.connections.sqlite.database');
        
        if (!$dbPath || $dbPath === ':memory:') {
            throw new RuntimeException("No se puede restaurar una base de datos en memoria.");
        }

        if (!File::exists($gzSourcePath)) {
            throw new RuntimeException("El archivo de respaldo no existe.");
        }

        $gzHandle = gzopen($gzSourcePath, 'rb');
        if ($gzHandle === false) {
            throw new RuntimeException("No se pudo abrir el archivo de respaldo para su lectura.");
        }

        // Antes de sobrescribir, cerramos cualquier conexión activa de PDO si es posible
        DB::disconnect();

        $destHandle = fopen($dbPath, 'wb');
        if ($destHandle === false) {
            gzclose($gzHandle);
            throw new RuntimeException("No se pudo abrir la base de datos de destino para su escritura.");
        }

        while (!gzeof($gzHandle)) {
            fwrite($destHandle, gzread($gzHandle, 1024 * 1024));
        }

        fclose($destHandle);
        gzclose($gzHandle);
        
        // Reconectar la base de datos para futuras consultas
        DB::reconnect();
    }
}
