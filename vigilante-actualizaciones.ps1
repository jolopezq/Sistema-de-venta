# ==============================================================================
# Ohana POS - Vigilante de Actualizaciones Automáticas
# Monitorea de forma continua el repositorio remoto en GitHub y aplica
# actualizaciones de manera segura cuando detecta nuevos commits en main.
# ==============================================================================

param(
    [int]$IntervalSeconds = 60,
    [string]$Branch = "main",
    [string]$Remote = "origin"
)

# Configurar codificación de consola en UTF-8
try {
    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
    $OutputEncoding = [System.Text.Encoding]::UTF8
} catch {}

$Host.UI.RawUI.WindowTitle = "Ohana POS - Vigilante de Actualizaciones"

$RootDir = $PSScriptRoot
if (-not $RootDir) {
    $RootDir = "c:\Sistema de Ventas"
}
Set-Location $RootDir

$LogDir = Join-Path $RootDir "backups"
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}
$LogFile = Join-Path $LogDir "vigilante.log"

# Detectar ruta de PHP
$phpExe = "php"
$phpArgs = @()
if (Test-Path "C:\Ohana-POS-Windows\php\php.exe") {
    $phpExe = "C:\Ohana-POS-Windows\php\php.exe"
    if (Test-Path "C:\Ohana-POS-Windows\php\php.ini") {
        $phpArgs = @("-c", "C:\Ohana-POS-Windows\php\php.ini")
    }
}

function Write-Log {
    param(
        [string]$Message,
        [ValidateSet("INFO", "SUCCESS", "WARN", "ERROR", "TITLE")]
        [string]$Level = "INFO"
    )
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $consoleColor = switch ($Level) {
        "SUCCESS" { "Green" }
        "WARN"    { "Yellow" }
        "ERROR"   { "Red" }
        "TITLE"   { "Cyan" }
        default   { "White" }
    }

    $prefix = switch ($Level) {
        "SUCCESS" { "[OK]" }
        "WARN"    { "[AVISO]" }
        "ERROR"   { "[ERROR]" }
        "TITLE"   { "[==]" }
        default   { "[INFO]" }
    }

    $formattedConsole = "[$timestamp] $prefix $Message"
    Write-Host $formattedConsole -ForegroundColor $consoleColor

    # Guardar en archivo de registro
    try {
        $fileEntry = "[$timestamp] [$Level] $Message"
        Add-Content -Path $LogFile -Value $fileEntry -Encoding UTF8 -ErrorAction SilentlyContinue
    } catch {}
}

function Show-DesktopNotification {
    param(
        [string]$Title,
        [string]$Message,
        [ValidateSet("Info", "Warning", "Error")]
        [string]$Type = "Info"
    )
    try {
        Add-Type -AssemblyName System.Windows.Forms -ErrorAction SilentlyContinue
        Add-Type -AssemblyName System.Drawing -ErrorAction SilentlyContinue

        $tipIcon = switch ($Type) {
            "Warning" { [System.Windows.Forms.ToolTipIcon]::Warning }
            "Error"   { [System.Windows.Forms.ToolTipIcon]::Error }
            default   { [System.Windows.Forms.ToolTipIcon]::Info }
        }

        $notify = New-Object System.Windows.Forms.NotifyIcon
        $iconPath = Join-Path $RootDir "ohana-pos.ico"
        if (Test-Path $iconPath) {
            $notify.Icon = New-Object System.Drawing.Icon($iconPath)
        } else {
            $notify.Icon = [System.Drawing.SystemIcons]::Information
        }

        $notify.BalloonTipIcon = $tipIcon
        $notify.BalloonTipTitle = $Title
        $notify.BalloonTipText = $Message
        $notify.Visible = $true
        $notify.ShowBalloonTip(7000)

        Start-Sleep -Milliseconds 600
        $notify.Dispose()
    } catch {}
}

function Get-ListeningPids {
    param([int]$Port)
    $pids = @()
    try {
        $lines = netstat -aon | Select-String ":$Port.*LISTENING"
        foreach ($line in $lines) {
            $tokens = ($line.ToString().Trim() -split "\s+")
            if ($tokens.Count -ge 5) {
                $pidVal = 0
                if ([int]::TryParse($tokens[$tokens.Count - 1], [ref]$pidVal) -and $pidVal -gt 0) {
                    $pids += $pidVal
                }
            }
        }
    } catch {}
    return $pids | Select-Object -Unique
}

Clear-Host
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "         OHANA ACAI POS - VIGILANTE DE ACTUALIZACIONES         " -ForegroundColor Yellow
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "  Repositorio Remoto : $Remote/$Branch" -ForegroundColor Gray
Write-Host "  Intervalo de sondeo: Cada $IntervalSeconds segundos" -ForegroundColor Gray
Write-Host "  Directorio del POS : $RootDir" -ForegroundColor Gray
Write-Host "  Archivo de Logs    : $LogFile" -ForegroundColor Gray
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "Presiona Ctrl + C en cualquier momento para detener el vigilante.`n" -ForegroundColor DarkGray

Show-DesktopNotification -Title "Ohana POS - Vigilante Activo" -Message "El vigilante de actualizaciones esta monitoreando cambios en GitHub cada $IntervalSeconds segundos." -Type "Info"

$consecutiveErrors = 0

while ($true) {
    # 1. Comprobar conectividad con GitHub y obtener ultimos commits
    $fetchSuccess = $false
    try {
        $null = git fetch $Remote $Branch 2>&1
        if ($LASTEXITCODE -eq 0) {
            $fetchSuccess = $true
            $consecutiveErrors = 0
        } else {
            $consecutiveErrors++
            if ($consecutiveErrors -le 1 -or ($consecutiveErrors % 5 -eq 0)) {
                Write-Log "Sin conexion con GitHub o repositorio remoto (intento #$consecutiveErrors). Reintentando en $IntervalSeconds s..." -Level "WARN"
            }
        }
    } catch {
        $consecutiveErrors++
        Write-Log "Error al ejecutar git fetch: $_" -Level "WARN"
    }

    if ($fetchSuccess) {
        $localCommit = ""
        $remoteCommit = ""

        try {
            $localCommit = (git rev-parse HEAD 2>$null).Trim()
            $remoteCommit = (git rev-parse "$Remote/$Branch" 2>$null).Trim()
        } catch {}

        if ([string]::IsNullOrWhiteSpace($localCommit) -or [string]::IsNullOrWhiteSpace($remoteCommit)) {
            Write-Log "No se pudieron obtener los hashes de los commits locales/remotos." -Level "WARN"
        } elseif ($localCommit -eq $remoteCommit) {
            $shortCommit = $localCommit.Substring(0, [Math]::Min(7, $localCommit.Length))
            Write-Log "Sistema al dia (version: $shortCommit). Proxima comprobacion en $IntervalSeconds s." -Level "INFO"
        } else {
            # Comprobar si el remoto tiene commits nuevos por delante del local
            $behindCountStr = (git rev-list --count "HEAD..$Remote/$Branch" 2>$null).Trim()
            $behindCount = 0
            [int]::TryParse($behindCountStr, [ref]$behindCount) | Out-Null

            $aheadCountStr = (git rev-list --count "$Remote/$Branch..HEAD" 2>$null).Trim()
            $aheadCount = 0
            [int]::TryParse($aheadCountStr, [ref]$aheadCount) | Out-Null

            if ($behindCount -gt 0) {
                Write-Host ""
                Write-Log "NUEVA ACTUALIZACION DETECTADA EN GITHUB ($behindCount commits nuevos)" -Level "SUCCESS"

                # Mostrar lista de nuevos commits
                $commitList = git log "HEAD..$Remote/$Branch" --oneline -n 5
                foreach ($c in $commitList) {
                    Write-Host "    * $c" -ForegroundColor Yellow
                }

                Show-DesktopNotification -Title "Nueva Actualizacion Detectada" -Message "Descargando $behindCount cambio(s) desde GitHub y aplicando al sistema..." -Type "Info"

                # 1. Respaldo preventivo obligatorio de la base de datos
                Write-Log "[1/6] Creando copia de seguridad preventiva de la base de datos..." -Level "INFO"
                $backupBat = Join-Path $RootDir "respaldo-diario.bat"
                if (Test-Path $backupBat) {
                    Start-Process -FilePath "cmd.exe" -ArgumentList "/c `"$backupBat`"" -Wait -WindowStyle Hidden
                    Write-Log "Copia de seguridad preventiva completada." -Level "SUCCESS"
                }

                # 2. Proteger cambios locales no commiteados con un stash preventivo
                $isDirty = (git status --porcelain 2>$null)
                if ($isDirty) {
                    $stashTag = "AutoBackup_Vigilante_" + (Get-Date -Format "yyyyMMdd_HHmmss")
                    Write-Log "[2/6] Guardando cambios locales en stash preventivo ($stashTag)..." -Level "WARN"
                    git stash save "$stashTag" 2>&1 | Out-Null
                } else {
                    Write-Log "[2/6] Espacio de trabajo limpio, no requiere stash." -Level "INFO"
                }

                # 3. Analizar qué archivos cambiaron para optimizar la compilacion
                Write-Log "[3/6] Analizando archivos afectados por la actualizacion..." -Level "INFO"
                $changedFiles = @()
                try {
                    $changedFiles = git diff --name-only "HEAD" "$Remote/$Branch"
                } catch {}

                $hasMigrations = ($changedFiles | Where-Object { $_ -match "backend/database/migrations" -or $_ -match "backend/database/seeders" })
                $hasBackendCode = ($changedFiles | Where-Object { $_ -match "^backend/" })
                $hasFrontendCode = ($changedFiles | Where-Object { $_ -match "^frontend/" })

                # 4. Descargar cambios con git pull
                Write-Log "[4/6] Descargando ultimos cambios (git pull $Remote $Branch)..." -Level "INFO"
                $pullOutput = git pull $Remote $Branch 2>&1
                Write-Host ($pullOutput -join "`n") -ForegroundColor Gray

                # 5. Mantenimiento y migraciones de Backend
                Write-Log "[5/6] Ejecutando mantenimiento de backend y base de datos..." -Level "INFO"
                Push-Location (Join-Path $RootDir "backend")
                try {
                    if ($hasMigrations) {
                        Write-Log "-> Aplicando migraciones de base de datos pendientes..." -Level "INFO"
                        & $phpExe @phpArgs artisan migrate --force
                    }
                    if ($hasBackendCode) {
                        Write-Log "-> Limpiando cache de Laravel..." -Level "INFO"
                        & $phpExe @phpArgs artisan optimize:clear
                    }
                    if (-not (Test-Path (Join-Path $RootDir "backend\public\storage"))) {
                        & $phpExe @phpArgs artisan storage:link 2>&1 | Out-Null
                    }
                } catch {
                    Write-Log "Error durante mantenimiento de backend: $_" -Level "ERROR"
                }
                Pop-Location

                # 6. Compilacion de Frontend si hubo modificaciones
                if ($hasFrontendCode) {
                    Write-Log "[6/6] Detectados cambios en Frontend. Recompilando interfaz con Vite..." -Level "INFO"
                    Push-Location (Join-Path $RootDir "frontend")
                    try {
                        & npm.cmd run build
                        Write-Log "Copiando nueva version compilada a backend/public/..." -Level "INFO"
                        Copy-Item -Path (Join-Path $RootDir "frontend\dist\*") -Destination (Join-Path $RootDir "backend\public\") -Recurse -Force
                        Write-Log "Frontend compilado y publicado con exito." -Level "SUCCESS"
                    } catch {
                        Write-Log "Error al compilar el frontend: $_" -Level "ERROR"
                    }
                    Pop-Location
                } else {
                    Write-Log "[6/6] Sin cambios en Frontend. Compilacion omitida para mayor velocidad." -Level "INFO"
                }

                # 7. Reiniciar servidor backend limpiamente si esta corriendo
                $activePids = Get-ListeningPids -Port 8000
                if ($activePids.Count -gt 0) {
                    Write-Log "Reiniciando servidor backend (puerto 8000) para aplicar cambios..." -Level "INFO"
                    foreach ($p in $activePids) {
                        try {
                            Stop-Process -Id $p -Force -ErrorAction SilentlyContinue
                        } catch {}
                    }
                    Start-Sleep -Seconds 1
                    $serverLauncher = Join-Path $RootDir "iniciar-servidor-silencioso.vbs"
                    if (Test-Path $serverLauncher) {
                        Start-Process -FilePath "wscript.exe" -ArgumentList "`"$serverLauncher`"" -WindowStyle Hidden
                    } else {
                        Start-Process -FilePath "cmd.exe" -ArgumentList "/c `"$RootDir\iniciar-servidor.bat`"" -WindowStyle Hidden
                    }
                    Write-Log "Servidor backend reiniciado exitosamente en puerto 8000." -Level "SUCCESS"
                }

                $newLocalCommit = (git rev-parse HEAD 2>$null).Trim()
                $newShort = $newLocalCommit.Substring(0, [Math]::Min(7, $newLocalCommit.Length))

                Write-Host ""
                Write-Log "================================================================" -Level "SUCCESS"
                Write-Log "SISTEMA ACTUALIZADO CON EXITO A LA VERSION $newShort" -Level "SUCCESS"
                Write-Log "================================================================" -Level "SUCCESS"
                Write-Host ""

                Show-DesktopNotification -Title "Ohana POS Actualizado" -Message "El sistema se actualizo a la version $newShort con exito. Presiona F5 en el navegador si esta abierto." -Type "Info"

            } elseif ($aheadCount -gt 0) {
                Write-Log "El repositorio local tiene $aheadCount commit(s) por delante del remoto. Actualizacion omitida para proteger cambios locales." -Level "WARN"
            }
        }
    }

    # Esperar el intervalo configurado
    Start-Sleep -Seconds $IntervalSeconds
}
