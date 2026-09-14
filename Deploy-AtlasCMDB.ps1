[CmdletBinding()]
param(
    [string]$Repository = 'https://github.com/Gercsi/atlas-cmdb.git',
    [ValidatePattern('^[A-Za-z0-9._/-]+$')][string]$Branch = 'main',
    [string]$ApacheRoot = 'C:\Apache24',
    [string]$TargetPath = '',
    [string]$VHostPath = '',
    [ValidatePattern('^[A-Za-z0-9.-]+$')][string]$ServerName = 'atlas-cmdb.local',
    [ValidateRange(1, 65535)][int]$Port = 80,
    [string]$Config = '',
    [string]$Php = '',
    [string]$ApacheServiceName = 'Apache2.4',
    [switch]$AllowRemote,
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

function Resolve-Php {
    param([string]$Requested, [string]$Root)
    $candidates = @($Requested, (Join-Path $Root 'php\php.exe'), 'C:\php\php.exe', 'C:\xampp\php\php.exe') | Where-Object { $_ }
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { return [IO.Path]::GetFullPath($candidate) }
    }
    throw 'A PHP nem található. Add meg a -Php paraméterrel a php.exe útvonalát.'
}

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Stop-AtlasWorker {
    param([Parameter(Mandatory)][string]$Storage, [Parameter(Mandatory)][string]$Target)
    $processFile = Join-Path $Storage 'processes.json'
    if (-not (Test-Path -LiteralPath $processFile)) { return $false }
    try { $record = Get-Content -LiteralPath $processFile -Raw | ConvertFrom-Json }
    catch { Write-Warning 'A processes.json nem olvasható; a workert nem állítottuk le.'; return $false }
    if (-not $record.PSObject.Properties['project'] -or (Normalize-Path ([string]$record.project)) -ne (Normalize-Path $Target)) {
        Write-Warning 'A processes.json másik projektet jelöl; a workert nem állítottuk le.'
        return $false
    }
    $workerProperty = $record.PSObject.Properties['worker']
    if (-not $workerProperty) { return $false }
    $processId = [int]$workerProperty.Value
    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
    if (-not $process -or $process.ProcessName -ne 'php') { return $false }
    $recordedAt = (Get-Item -LiteralPath $processFile).LastWriteTime
    $command = Get-CimInstance Win32_Process -Filter "ProcessId = $processId" -ErrorAction SilentlyContinue
    if ($process.StartTime -gt $recordedAt.AddSeconds(5) -or -not $command -or ([string]$command.CommandLine) -notlike '*worker.php*') {
        Write-Warning "A(z) $processId folyamata nem azonosítható Atlas-workerként; nem állítottuk le."
        return $false
    }
    Stop-Process -Id $processId
    return $true
}

function Start-AtlasWorker {
    param(
        [Parameter(Mandatory)][string]$Target,
        [Parameter(Mandatory)][string]$PhpPath,
        [Parameter(Mandatory)][string]$Storage,
        [Parameter(Mandatory)][string]$HostName,
        [Parameter(Mandatory)][int]$ListenPort
    )
    $worker = Start-Process -FilePath $PhpPath -ArgumentList @('-d', 'extension=zip', '-d', 'extension=gd', 'worker.php') -WorkingDirectory $Target -RedirectStandardOutput (Join-Path $Storage 'worker-out.log') -RedirectStandardError (Join-Path $Storage 'worker-error.log') -WindowStyle Hidden -PassThru
    @{
        worker = $worker.Id
        project = $Target
        mode = 'apache'
        server_name = $HostName
        port = $ListenPort
    } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Storage 'processes.json')
}

function New-VHostContent {
    param(
        [Parameter(Mandatory)][string]$DocumentRoot,
        [Parameter(Mandatory)][string]$ConfigPath,
        [Parameter(Mandatory)][string]$HostName,
        [Parameter(Mandatory)][int]$ListenPort,
        [Parameter(Mandatory)][string]$LogsDirectory,
        [Parameter(Mandatory)][bool]$RemoteAccess
    )
    $document = $DocumentRoot.Replace('\', '/')
    $configuration = $ConfigPath.Replace('\', '/')
    $logs = $LogsDirectory.Replace('\', '/')
    $remoteValue = if ($RemoteAccess) { '1' } else { '0' }
    $accessRequirement = if ($RemoteAccess) { 'Require all granted' } else { 'Require local' }
    return @"
# Atlas CMDB virtual host
# A PHP-kezelőt (mod_php vagy FastCGI) az Apache globális konfigurációjában kell beállítani.
<VirtualHost *:$ListenPort>
    ServerName $HostName
    DocumentRoot "$document"
    DirectoryIndex index.html

    SetEnv CMDB_CONFIG "$configuration"
    SetEnv CMDB_ALLOWED_HOSTS "$HostName"
    SetEnv CMDB_ALLOW_REMOTE "$remoteValue"

    <Directory "$document">
        Options -Indexes
        AllowOverride All
        $accessRequirement
    </Directory>

    ErrorLog "$logs/atlas-cmdb-error.log"
    CustomLog "$logs/atlas-cmdb-access.log" combined
</VirtualHost>
"@
}

function Restart-Apache {
    param([Parameter(Mandatory)][string]$ServiceName)
    $service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if (-not $service) {
        throw "Az Apache szolgáltatás nem található: $ServiceName. Telepítsd szolgáltatásként, vagy add meg az -ApacheServiceName paramétert."
    }
    if ($service.Status -eq 'Running') { Restart-Service -Name $ServiceName -Force }
    else { Start-Service -Name $ServiceName }
    (Get-Service -Name $ServiceName).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
}

function Wait-Atlas {
    param([Parameter(Mandatory)][string]$HostName, [Parameter(Mandatory)][int]$ListenPort)
    $portSuffix = if ($ListenPort -eq 80) { '' } else { ":$ListenPort" }
    $uri = "http://$HostName$portSuffix/api.php?r=session"
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri $uri -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -eq 200) { return $uri }
        } catch { Start-Sleep -Seconds 1 }
    }
    throw "Az alkalmazás 30 másodpercen belül nem válaszolt: $uri"
}

$gitCommand = Get-Command git -ErrorAction SilentlyContinue
if (-not $gitCommand) { throw 'A Git nem található a PATH változóban.' }
$apache = [IO.Path]::GetFullPath($ApacheRoot).TrimEnd('\', '/')
$target = if ($TargetPath) { [IO.Path]::GetFullPath($TargetPath).TrimEnd('\', '/') } else { Join-Path $apache 'htdocs\atlas' }
$vhost = if ($VHostPath) { [IO.Path]::GetFullPath($VHostPath) } else { Join-Path $apache 'conf\extra\atlas-cmdb.conf' }
$httpdConfig = Join-Path $apache 'conf\httpd.conf'
$httpd = Join-Path $apache 'bin\httpd.exe'
$phpPath = Resolve-Php -Requested $Php -Root $apache
$configPath = if ($Config) { [IO.Path]::GetFullPath($Config) } else { Join-Path $apache 'Atlas-cmdb-private\config.json' }
$documentRoot = Join-Path $target 'public'

foreach ($required in @($apache, (Join-Path $apache 'htdocs'), (Join-Path $apache 'conf'), $httpdConfig, $httpd)) {
    if (-not (Test-Path -LiteralPath $required)) { throw "Hiányzó Apache-összetevő: $required" }
}
$targetRoot = [IO.Path]::GetPathRoot($target).TrimEnd('\', '/')
if (-not $target -or $target -eq $targetRoot) { throw 'A célmappa nem lehet meghajtógyökér.' }
if (-not $DryRun -and -not (Test-Administrator)) { throw 'A deployt rendszergazdaként indított PowerShellből futtasd.' }

Invoke-Native -FilePath $gitCommand.Source -Arguments @('ls-remote', '--exit-code', '--heads', $Repository, "refs/heads/$Branch") -Description 'A távoli ág ellenőrzése' | Out-Null
$targetExists = Test-Path -LiteralPath $target
$isRepository = $targetExists -and (Test-Path -LiteralPath (Join-Path $target '.git'))
$targetIsEmpty = $false
$hasExistingApp = $false
$displacedTarget = ''
if ($targetExists -and -not $isRepository) {
    $targetIsEmpty = @(Get-ChildItem -LiteralPath $target -Force).Count -eq 0
    $hasExistingApp = (Test-Path -LiteralPath (Join-Path $target 'vendor\autoload.php')) -and
        (Test-Path -LiteralPath (Join-Path $target 'backup.php')) -and
        (Test-Path -LiteralPath (Join-Path $target 'console.php'))
    if (-not $targetIsEmpty -and -not $Force) {
        throw "A célmappa nem üres és nem Git-repó: $target. A biztonságos cseréhez futtasd újra -Force kapcsolóval; a telepítő előbb átnevezi és megőrzi a teljes jelenlegi mappát."
    }
    if (-not $targetIsEmpty) {
        $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
        $displacedTarget = "$target.predeploy-$stamp"
        $suffix = 1
        while (Test-Path -LiteralPath $displacedTarget) {
            $displacedTarget = "$target.predeploy-$stamp-$suffix"
            $suffix++
        }
    }
}
if ($isRepository) {
    $origin = (Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'remote', 'get-url', 'origin') -Description 'A távoli repó lekérdezése' | Select-Object -First 1).Trim()
    if ((Normalize-Repository $origin) -ne (Normalize-Repository $Repository)) { throw "A célmappa másik repóhoz tartozik: $origin" }
    $changes = @(Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'status', '--porcelain') -Description 'A munkakönyvtár ellenőrzése')
    if ($changes.Count -gt 0 -and -not $Force) { throw 'A célmappában nem commitolt módosítások vannak. Ellenőrizd őket, vagy használd a -Force kapcsolót.' }
}

Write-Host "Forrás: $Repository ($Branch)"
Write-Host "Apache: $apache"
Write-Host "Cél: $target"
Write-Host "VHost: $vhost"
Write-Host "Cím: http://$ServerName$(if ($Port -eq 80) { '' } else { ":$Port" })/"
Write-Host "Konfiguráció: $configPath"
Write-Host "Távoli elérés: $(if ($AllowRemote) { 'engedélyezve' } else { 'csak a webszerverről' })"
if ($targetExists -and -not $isRepository -and $targetIsEmpty) { Write-Host 'A létező célmappa üres; az alkalmazás közvetlenül ide települ.' }
if ($displacedTarget) { Write-Host "A jelenlegi, nem Git-alapú mappa biztonsági másolata: $displacedTarget" }
if ($DryRun) { Write-Host 'DryRun: az előfeltételek rendben vannak; nem történt módosítás.'; exit 0 }

$mutex = [Threading.Mutex]::new($false, 'Local\AtlasCMDBApacheDeploy')
if (-not $mutex.WaitOne(0)) { $mutex.Dispose(); throw 'Már fut egy Atlas CMDB deploy.' }
$previousConfig = [Environment]::GetEnvironmentVariable('CMDB_CONFIG', 'Process')
$hadConfig = Test-Path Env:CMDB_CONFIG
$previousCommit = ''
$updated = $false
$workerStopped = $false
$workerStarted = $false
$previousTargetMoved = $false
$apacheConfigChanged = $false
$vhostExisted = Test-Path -LiteralPath $vhost
$vhostOriginal = if ($vhostExisted) { [IO.File]::ReadAllText($vhost) } else { '' }
$httpdOriginal = [IO.File]::ReadAllText($httpdConfig)

try {
    $env:CMDB_CONFIG = $configPath
    if (-not $isRepository -and $hasExistingApp) {
        if ((Test-Path -LiteralPath $configPath) -and -not $SkipBackup) {
            Write-Host 'A meglévő alkalmazás adatbázis- és konfigurációmentésének elkészítése...'
            Invoke-Native -FilePath $phpPath -Arguments @('-d', 'extension=zip', '-d', 'extension=gd', (Join-Path $target 'backup.php')) -Description 'A telepítés előtti mentés' | ForEach-Object { Write-Host $_ }
        }
        $storage = (& $phpPath (Join-Path $target 'console.php') storage-path)
        if ($LASTEXITCODE -ne 0 -or -not $storage) { throw 'A meglévő alkalmazás privát tárolóútvonala nem kérdezhető le.' }
        $workerStopped = Stop-AtlasWorker -Storage ([string]$storage).Trim() -Target $target
    }
    if ($isRepository) {
        $previousCommit = (Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'rev-parse', 'HEAD') -Description 'Az aktuális verzió lekérdezése' | Select-Object -First 1).Trim()
        if ((Test-Path -LiteralPath $configPath) -and -not $SkipBackup) {
            Write-Host 'Adatbázis- és konfigurációmentés készítése...'
            Invoke-Native -FilePath $phpPath -Arguments @('-d', 'extension=zip', '-d', 'extension=gd', (Join-Path $target 'backup.php')) -Description 'A telepítés előtti mentés' | ForEach-Object { Write-Host $_ }
        }
        $storage = (& $phpPath (Join-Path $target 'console.php') storage-path)
        if ($LASTEXITCODE -ne 0 -or -not $storage) { throw 'A privát tároló útvonala nem kérdezhető le.' }
        $workerStopped = Stop-AtlasWorker -Storage ([string]$storage).Trim() -Target $target
        Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'fetch', '--prune', 'origin', $Branch) -Description 'A kiadás letöltése' | ForEach-Object { Write-Host $_ }
        Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'checkout', '-B', $Branch, "origin/$Branch") -Description 'A kiadási ág aktiválása' | ForEach-Object { Write-Host $_ }
        if ($Force) { Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'clean', '-fd') -Description 'Az idegen fájlok eltávolítása' | ForEach-Object { Write-Host $_ } }
        $updated = $true
    } else {
        if ($displacedTarget) {
            Write-Host "A jelenlegi célmappa megőrzése itt: $displacedTarget"
            Move-Item -LiteralPath $target -Destination $displacedTarget
            $previousTargetMoved = $true
        }
        Invoke-Native -FilePath $gitCommand.Source -Arguments @('clone', '--depth', '1', '--branch', $Branch, '--single-branch', $Repository, $target) -Description 'Az alkalmazás letöltése' | ForEach-Object { Write-Host $_ }
        $updated = $true
    }

    foreach ($required in @('vendor\autoload.php', 'public\index.html', 'src\App.php', 'worker.php')) {
        if (-not (Test-Path -LiteralPath (Join-Path $target $required))) { throw "Hiányos kiadás: $required" }
    }

    $vhostDirectory = Split-Path -Parent $vhost
    if (-not (Test-Path -LiteralPath $vhostDirectory)) { New-Item -ItemType Directory -Path $vhostDirectory | Out-Null }
    $vhostContent = New-VHostContent -DocumentRoot $documentRoot -ConfigPath $configPath -HostName $ServerName -ListenPort $Port -LogsDirectory (Join-Path $apache 'logs') -RemoteAccess ([bool]$AllowRemote)
    [IO.File]::WriteAllText($vhost, $vhostContent, [Text.UTF8Encoding]::new($false))
    $includePath = $vhost.Replace('\', '/')
    if ($httpdOriginal -notmatch '(?im)^\s*Include\s+["'']?.*atlas-cmdb\.conf["'']?\s*$') {
        [IO.File]::AppendAllText($httpdConfig, "`r`n# Atlas CMDB virtual host`r`nInclude `"$includePath`"`r`n", [Text.UTF8Encoding]::new($false))
    }
    $apacheConfigChanged = $true
    Invoke-Native -FilePath $httpd -Arguments @('-t', '-f', $httpdConfig) -Description 'Az Apache konfigurációellenőrzése' | ForEach-Object { Write-Host $_ }

    if (Test-Path -LiteralPath $configPath) {
        Write-Host 'Adatbázis-migráció futtatása...'
        Invoke-Native -FilePath $phpPath -Arguments @((Join-Path $target 'install.php')) -Description 'Az adatbázis-migráció' | ForEach-Object { Write-Host $_ }
    } else { Write-Host 'Még nincs konfiguráció; az első indítási telepítő fog megjelenni.' }

    if (-not $NoRestart) {
        Restart-Apache -ServiceName $ApacheServiceName
        $storage = (& $phpPath (Join-Path $target 'console.php') storage-path)
        if ($LASTEXITCODE -ne 0 -or -not $storage) { throw 'A worker tárolója nem kérdezhető le.' }
        Start-AtlasWorker -Target $target -PhpPath $phpPath -Storage ([string]$storage).Trim() -HostName $ServerName -ListenPort $Port
        $workerStarted = $true
        $healthUrl = Wait-Atlas -HostName $ServerName -ListenPort $Port
        Write-Host "Deploy kész, az Atlas elérhető: $($healthUrl -replace '/api\.php\?r=session$', '/')"
    } else { Write-Host 'Deploy kész. A -NoRestart miatt az Apache és a worker nem indult újra.' }
} catch {
    $failure = $_
    if ($workerStarted) {
        try {
            $runtimeStorage = (& $phpPath (Join-Path $target 'console.php') storage-path)
            if ($LASTEXITCODE -eq 0 -and $runtimeStorage) { Stop-AtlasWorker -Storage ([string]$runtimeStorage).Trim() -Target $target | Out-Null }
        } catch { Write-Warning "A sikertelen kiadás workerét nem sikerült leállítani: $($_.Exception.Message)" }
    }
    if ($apacheConfigChanged) {
        try {
            [IO.File]::WriteAllText($httpdConfig, $httpdOriginal, [Text.UTF8Encoding]::new($false))
            if ($vhostExisted) { [IO.File]::WriteAllText($vhost, $vhostOriginal, [Text.UTF8Encoding]::new($false)) }
            elseif (Test-Path -LiteralPath $vhost) { Remove-Item -LiteralPath $vhost }
        } catch { Write-Warning "Az Apache-konfiguráció visszaállítása sem sikerült: $($_.Exception.Message)" }
    }
    if ($updated -and $previousCommit -and (Test-Path -LiteralPath (Join-Path $target '.git'))) {
        try { Invoke-Native -FilePath $gitCommand.Source -Arguments @('-C', $target, 'reset', '--hard', $previousCommit) -Description 'A korábbi verzió visszaállítása' | Out-Null }
        catch { Write-Warning "A kód automatikus visszaállítása sem sikerült: $($_.Exception.Message)" }
    }
    if ($previousTargetMoved -and (Test-Path -LiteralPath $displacedTarget)) {
        try {
            $targetParent = [IO.Path]::GetFullPath((Split-Path -Parent $target)).TrimEnd('\', '/')
            $backupParent = [IO.Path]::GetFullPath((Split-Path -Parent $displacedTarget)).TrimEnd('\', '/')
            if ((Normalize-Path $targetParent) -ne (Normalize-Path $backupParent)) { throw 'A visszaállítási mappák szülőútvonala eltér.' }
            if (Test-Path -LiteralPath $target) { Remove-Item -LiteralPath $target -Recurse -Force }
            Move-Item -LiteralPath $displacedTarget -Destination $target
            $previousTargetMoved = $false
            Write-Warning 'A sikertelen telepítés után a korábbi célmappa visszaállt.'
        } catch { Write-Warning "A korábbi célmappa automatikus visszaállítása sem sikerült: $($_.Exception.Message)" }
    }
    if (($workerStopped -or $workerStarted) -and -not $NoRestart -and (Test-Path -LiteralPath $configPath)) {
        try {
            Restart-Apache -ServiceName $ApacheServiceName
            $runtimeStorage = (& $phpPath (Join-Path $target 'console.php') storage-path)
            Start-AtlasWorker -Target $target -PhpPath $phpPath -Storage ([string]$runtimeStorage).Trim() -HostName $ServerName -ListenPort $Port
        } catch { Write-Warning "A korábbi alkalmazás újraindítása sem sikerült: $($_.Exception.Message)" }
    }
    throw $failure
} finally {
    if ($hadConfig) { $env:CMDB_CONFIG = $previousConfig } else { Remove-Item Env:CMDB_CONFIG -ErrorAction SilentlyContinue }
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}
