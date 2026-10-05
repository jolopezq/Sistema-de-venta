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
     *
     * Pasos: descomprime a un temporal, verifica que sea una BD SQLite íntegra de
     * este sistema, guarda una copia de la BD actual en storage/app/backups y
     * finalmente reemplaza el archivo de forma atómica (rename).
     *
     * @param string $gzSourcePath Ruta del archivo .gz a restaurar.
     * @return string Ruta de la copia de seguridad previa a la restauración.
     * @throws RuntimeException
     */
    public function restoreCompressedSqliteBackup(string $gzSourcePath): string
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

        // El temporal vive junto a la BD para que el rename final sea atómico (mismo disco)
        $timestamp = date('Y-m-d_His');
        $tempRestorePath = dirname($dbPath) . "/restore_{$timestamp}.sqlite";

        try {
            $this->gunzipFile($gzSourcePath, $tempRestorePath);
            $this->assertValidSqliteBackup($tempRestorePath);

            // Copia de la BD actual antes de sobrescribirla
            $backupDir = storage_path('app/backups');
            if (!File::exists($backupDir)) {
                File::makeDirectory($backupDir, 0755, true);
            }
            $preRestorePath = "{$backupDir}/pre_restore_{$timestamp}.sqlite";
            DB::statement("VACUUM INTO '" . str_replace("'", "''", $preRestorePath) . "'");

            DB::disconnect();

            // Los archivos WAL/SHM de la BD anterior no deben aplicarse sobre la nueva
            foreach (["{$dbPath}-wal", "{$dbPath}-shm"] as $sidecar) {
                if (File::exists($sidecar)) {
                    File::delete($sidecar);
                }
            }

            if (!rename($tempRestorePath, $dbPath)) {
                throw new RuntimeException("No se pudo reemplazar la base de datos actual.");
            }
        } finally {
            if (File::exists($tempRestorePath)) {
                File::delete($tempRestorePath);
            }
            DB::reconnect();
        }

        return $preRestorePath;
    }

    /**
     * Descomprime un archivo GZIP en bloques de 1MB.
     */
    private function gunzipFile(string $gzSourcePath, string $destPath): void
    {
        $gzHandle = gzopen($gzSourcePath, 'rb');
        if ($gzHandle === false) {
            throw new RuntimeException("No se pudo abrir el archivo de respaldo para su lectura.");
        }

        $destHandle = fopen($destPath, 'wb');
        if ($destHandle === false) {
            gzclose($gzHandle);
            throw new RuntimeException("No se pudo crear el archivo temporal de restauración.");
        }

        while (!gzeof($gzHandle)) {
            fwrite($destHandle, gzread($gzHandle, 1024 * 1024));
        }

        fclose($destHandle);
        gzclose($gzHandle);
    }

    /**
     * Verifica que el archivo sea una BD SQLite íntegra que contenga las tablas del sistema.
     *
     * @throws RuntimeException
     */
    private function assertValidSqliteBackup(string $path): void
    {
        $handle = fopen($path, 'rb');
        $header = $handle ? fread($handle, 16) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($header !== "SQLite format 3\0") {
            throw new RuntimeException("El archivo no es una copia de seguridad SQLite válida.");
        }

        try {
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                throw new RuntimeException("La copia de seguridad está dañada ({$integrity}).");
            }

            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
            $missing = array_diff(['users', 'sales', 'products', 'migrations'], $tables);
            if (!empty($missing)) {
                throw new RuntimeException("La copia de seguridad no pertenece a este sistema (faltan tablas: " . implode(', ', $missing) . ").");
            }
        } catch (\PDOException $e) {
            throw new RuntimeException("No se pudo leer la copia de seguridad: " . $e->getMessage());
        } finally {
            $pdo = null;
        }
    }
}
