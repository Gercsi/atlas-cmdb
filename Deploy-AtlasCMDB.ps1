[CmdletBinding()]
param(
    [string]$Repository = 'https://github.com/Gercsi/atlas-cmdb.git',
    [ValidatePattern('^[A-Za-z0-9._/-]+$')][string]$Branch = 'main',
    [string]$TargetPath = 'C:\xampp\htdocs\Atlas-cmdb',
    [ValidateRange(1, 65535)][int]$Port = 8088,
    [string]$Config = '',
    [string]$Php = 'C:\xampp\php\php.exe',
    [switch]$SkipBackup,
    [switch]$NoRestart,
    [switch]$Force,
    [switch]$DryRun
)

Set-StrictMode -Version 3.0
$ErrorActionPreference = 'Stop'

function Invoke-Native {
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$Arguments,
        [Parameter(Mandatory)][string]$Description
    )
    $output = @(& $FilePath @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "$Description sikertelen.`n$($output -join [Environment]::NewLine)"
    }
    return $output
}

function Normalize-Path {
    param([Parameter(Mandatory)][string]$Path)
    return [IO.Path]::GetFullPath($Path).TrimEnd('\', '/').ToLowerInvariant()
}

function Normalize-Repository {
    param([Parameter(Mandatory)][string]$Url)
    $value = $Url.Trim().TrimEnd('/').ToLowerInvariant()
    if ($value.EndsWith('.git')) { $value = $value.Substring(0, $value.Length - 4) }
    return $value
}

function Get-DefaultConfigPath {
    param([Parameter(Mandatory)][string]$Target)
    $projectParent = Split-Path -Parent $Target
    $privateBase = $projectParent
    if ((Split-Path -Leaf $projectParent).ToLowerInvariant() -in @('htdocs', 'www', 'html', 'wwwroot')) {
        $privateBase = Split-Path -Parent $projectParent
    }
    return Join-Path $privateBase ((Split-Path -Leaf $Target) + '-private\config.json')
}

function Stop-AtlasProcesses {
    param(
        [Parameter(Mandatory)][string]$Storage,
        [Parameter(Mandatory)][string]$Target
    )
    $processFile = Join-Path $Storage 'processes.json'
    if (-not (Test-Path -LiteralPath $processFile)) { return 0 }
    try { $record = Get-Content -LiteralPath $processFile -Raw | ConvertFrom-Json }
    catch { Write-Warning 'A processes.json nem olvasható; nem állítottunk le folyamatot.'; return 0 }
    if (-not $record.PSObject.Properties['project'] -or (Normalize-Path ([string]$record.project)) -ne (Normalize-Path $Target)) {
        Write-Warning 'A processes.json másik projektet jelöl; nem állítottunk le folyamatot.'
        return 0
    }
    $recordedAt = (Get-Item -LiteralPath $processFile).LastWriteTime
    $stopped = 0
    foreach ($entry in @(@('server', 'public/router.php'), @('worker', 'worker.php'))) {
        $property = $record.PSObject.Properties[$entry[0]]
        if (-not $property) { continue }
        $processId = [int]$property.Value
        $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
        if (-not $process -or $process.ProcessName -ne 'php') { continue }
        if ($process.StartTime -gt $recordedAt.AddSeconds(5)) {
            Write-Warning "A(z) $processId folyamata újabb a folyamatleírónál; nem állítottuk le."
            continue
        }
        $command = Get-CimInstance Win32_Process -Filter "ProcessId = $processId" -ErrorAction SilentlyContinue
        if (-not $command -or ([string]$command.CommandLine) -notlike "*$($entry[1])*") {
            Write-Warning "A(z) $processId folyamata nem azonosítható Atlas-folyamatként; nem állítottuk le."
            continue
        }
        Stop-Process -Id $processId
        $stopped++
    }
    return $stopped
}

function Start-Atlas {
    param(
        [Parameter(Mandatory)][string]$Target,
        [Parameter(Mandatory)][string]$PhpPath,
        [Parameter(Mandatory)][int]$ListenPort,
        [string]$ConfigPath = ''
    )
    $arguments = @('-Port', $ListenPort, '-Php', $PhpPath)
    if ($ConfigPath) { $arguments += @('-Config', $ConfigPath) }
    & (Join-Path $Target 'Start-CMDB.ps1') @arguments
}

function Wait-Atlas {
    param([Parameter(Mandatory)][int]$ListenPort)
    $uri = "http://127.0.0.1:$ListenPort/api/v1/session"
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri $uri -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -eq 200) { return }
        } catch {
            Start-Sleep -Seconds 1
        }
    }
    throw "Az alkalmazás 30 másodpercen belül nem válaszolt: $uri"
}

$gitCommand = Get-Command git -ErrorAction SilentlyContinue
if (-not $gitCommand) { throw 'A Git nem található a PATH változóban.' }
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw "A PHP nem található: $Php" }

$target = [IO.Path]::GetFullPath($TargetPath).TrimEnd('\', '/')
$targetRoot = [IO.Path]::GetPathRoot($target).TrimEnd('\', '/')
if (-not $target -or $target -eq $targetRoot) { throw 'A célmappa nem lehet meghajtógyökér.' }
$configPath = if ($Config) { [IO.Path]::GetFullPath($Config) } else { Get-DefaultConfigPath $target }
$targetExists = Test-Path -LiteralPath $target
$isRepository = $targetExists -and (Test-Path -LiteralPath (Join-Path $target '.git'))

Invoke-Native -FilePath $gitCommand.Source -Arguments @('ls-remote', '--exit-code', '--heads', $Repository, "refs/heads/$Branch") -Description 'A távoli ág ellenőrzése' | Out-Null

if ($targetExists -and -not $isRepository) {
    throw "A célmappa létezik, de nem Git-repó: $target. Helyezd át, vagy válassz másik TargetPath értéket."
}

if ($isRepository) {
    $origin = (Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'remote', 'get-url', 'origin') -Description 'A távoli repó lekérdezése' | Select-Object -First 1).Trim()
    if ((Normalize-Repository $origin) -ne (Normalize-Repository $Repository)) {
        throw "A célmappa másik repóhoz tartozik: $origin"
    }
    $changes = @(Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'status', '--porcelain') -Description 'A munkakönyvtár ellenőrzése')
    if ($changes.Count -gt 0 -and -not $Force) {
        throw 'A célmappában nem commitolt módosítások vannak. Ellenőrizd őket, vagy használd a -Force kapcsolót.'
    }
}

Write-Host "Forrás: $Repository ($Branch)"
Write-Host "Cél: $target"
Write-Host "Konfiguráció: $configPath"
if ($DryRun) {
    Write-Host 'DryRun: a távoli ág és a helyi feltételek rendben vannak; nem történt módosítás.'
    exit 0
}

$mutex = [Threading.Mutex]::new($false, 'Local\AtlasCMDBDeploy')
if (-not $mutex.WaitOne(0)) { throw 'Már fut egy Atlas CMDB deploy.' }
$previousConfig = [Environment]::GetEnvironmentVariable('CMDB_CONFIG', 'Process')
$hadConfig = Test-Path Env:CMDB_CONFIG
$previousCommit = ''
$updated = $false
$stoppedProcesses = 0
$startAttempted = $false

try {
    $env:CMDB_CONFIG = $configPath
    if ($isRepository) {
        $previousCommit = (Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'rev-parse', 'HEAD') -Description 'Az aktuális verzió lekérdezése' | Select-Object -First 1).Trim()
        if ((Test-Path -LiteralPath $configPath) -and -not $SkipBackup) {
            Write-Host 'Adatbázis- és konfigurációmentés készítése...'
            Invoke-Native -FilePath $Php -Arguments @('-d', 'extension=zip', '-d', 'extension=gd', (Join-Path $target 'backup.php')) -Description 'A telepítés előtti mentés' | ForEach-Object { Write-Host $_ }
        }
        $storage = (& $Php (Join-Path $target 'console.php') storage-path)
        if ($LASTEXITCODE -ne 0 -or -not $storage) { throw 'A privát tároló útvonala nem kérdezhető le.' }
        $stoppedProcesses = Stop-AtlasProcesses -Storage ([string]$storage).Trim() -Target $target
        if ($stoppedProcesses) { Write-Host "$stoppedProcesses Atlas-folyamat leállítva." }
        Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'fetch', '--prune', 'origin', $Branch) -Description 'A kiadás letöltése' | ForEach-Object { Write-Host $_ }
        Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'checkout', '-B', $Branch, "origin/$Branch") -Description 'A kiadási ág aktiválása' | ForEach-Object { Write-Host $_ }
        if ($Force) {
            Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'clean', '-fd') -Description 'Az idegen fájlok eltávolítása' | ForEach-Object { Write-Host $_ }
        }
        $updated = $true
    } else {
        $parent = Split-Path -Parent $target
        if (-not (Test-Path -LiteralPath $parent)) { New-Item -ItemType Directory -Path $parent | Out-Null }
        Invoke-Native -FilePath $gitCommand.Source -Arguments @('clone', '--depth', '1', '--branch', $Branch, '--single-branch', $Repository, $target) -Description 'Az alkalmazás letöltése' | ForEach-Object { Write-Host $_ }
        $updated = $true
    }

    foreach ($required in @('vendor\autoload.php', 'public\index.html', 'src\App.php', 'Start-CMDB.ps1')) {
        if (-not (Test-Path -LiteralPath (Join-Path $target $required))) { throw "Hiányos kiadás: $required" }
    }

    if (Test-Path -LiteralPath $configPath) {
        Write-Host 'Adatbázis-migráció futtatása...'
        Invoke-Native -FilePath $Php -Arguments @((Join-Path $target 'install.php')) -Description 'Az adatbázis-migráció' | ForEach-Object { Write-Host $_ }
    } else {
        Write-Host 'Még nincs konfiguráció; az első indítási telepítő fog megjelenni.'
    }

    if (-not $NoRestart) {
        $portOwner = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
        if ($portOwner) { throw "A(z) $Port portot másik folyamat használja." }
        $startAttempted = $true
        Start-Atlas -Target $target -PhpPath $Php -ListenPort $Port -ConfigPath $configPath
        Wait-Atlas -ListenPort $Port
        Write-Host "Deploy kész, az Atlas elérhető: http://127.0.0.1:$Port/"
    } else {
        Write-Host 'Deploy kész. A -NoRestart miatt az alkalmazás nem indult el.'
    }
} catch {
    $failure = $_
    if ($startAttempted -and (Test-Path -LiteralPath (Join-Path $target 'console.php'))) {
        try {
            $runtimeStorage = (& $Php (Join-Path $target 'console.php') storage-path)
            if ($LASTEXITCODE -eq 0 -and $runtimeStorage) {
                Stop-AtlasProcesses -Storage ([string]$runtimeStorage).Trim() -Target $target | Out-Null
            }
        } catch { Write-Warning "A sikertelen kiadás folyamatait nem sikerült leállítani: $($_.Exception.Message)" }
    }
    if ($updated -and $previousCommit -and (Test-Path -LiteralPath (Join-Path $target '.git'))) {
        Write-Warning "A deploy sikertelen; a kód visszaállítása erre a verzióra: $previousCommit"
        try { Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'reset', '--hard', $previousCommit) -Description 'A korábbi verzió visszaállítása' | Out-Null }
        catch { Write-Warning "A kód automatikus visszaállítása sem sikerült: $($_.Exception.Message)" }
    }
    if ($stoppedProcesses -gt 0 -and -not $NoRestart) {
        try { Start-Atlas -Target $target -PhpPath $Php -ListenPort $Port -ConfigPath $configPath }
        catch { Write-Warning "A korábbi alkalmazás újraindítása sem sikerült: $($_.Exception.Message)" }
    }
    throw $failure
} finally {
    if ($hadConfig) { $env:CMDB_CONFIG = $previousConfig } else { Remove-Item Env:CMDB_CONFIG -ErrorAction SilentlyContinue }
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}
