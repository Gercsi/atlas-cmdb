param(
    [ValidateRange(1,65535)][int]$Port = 8088,
    [string]$Config = '',
    [string]$Php = 'C:\xampp\php\php.exe'
)
$ErrorActionPreference = 'Stop'
$project = $PSScriptRoot
if (-not (Test-Path -LiteralPath "$project\vendor\autoload.php")) { throw 'Először futtasd: composer install (lásd README.md).' }
if (-not (Test-Path -LiteralPath "$project\public\index.html")) { throw 'Először futtasd: pnpm install --frozen-lockfile, majd pnpm build.' }
if ($Config) { $env:CMDB_CONFIG = $Config }
$privateDirectory = & $Php "$project\console.php" storage-path
if ($LASTEXITCODE -ne 0) { throw 'A privát konfiguráció vagy tároló nem használható. Lásd a fenti hibát.' }
$privateDirectory = ([string]$privateDirectory).Trim()
if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
    Write-Output "A $Port port már foglalt. Nem indítottunk új példányt. Ha ez az Atlas: http://127.0.0.1:$Port"
    exit
}
if (-not (Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue)) {
    $mysql = 'C:\xampp\mysql\bin\mysqld.exe'
    if (Test-Path -LiteralPath $mysql) {
        Start-Process -FilePath $mysql -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini','--bind-address=127.0.0.1' -WindowStyle Hidden
    } else { Write-Warning 'Indítsd el külön a helyi MySQL/MariaDB szolgáltatást a telepítés előtt.' }
}
$server = Start-Process -FilePath $Php -ArgumentList '-d','extension=zip','-d','extension=gd','-d','display_errors=0','-d','upload_max_filesize=20M','-d','post_max_size=22M','-S',"127.0.0.1:$Port",'-t','public','public/router.php' -WorkingDirectory $project -RedirectStandardOutput "$privateDirectory\server-out.log" -RedirectStandardError "$privateDirectory\server-error.log" -WindowStyle Hidden -PassThru
$worker = Start-Process -FilePath $Php -ArgumentList '-d','extension=zip','-d','extension=gd','worker.php' -WorkingDirectory $project -RedirectStandardOutput "$privateDirectory\worker-out.log" -RedirectStandardError "$privateDirectory\worker-error.log" -WindowStyle Hidden -PassThru
@{server=$server.Id;worker=$worker.Id;port=$Port;project=$project} | ConvertTo-Json | Set-Content -LiteralPath "$privateDirectory\processes.json"
Write-Output "Atlas elindult: http://127.0.0.1:$Port"
Write-Output 'Első indításkor a böngészőben telepítheted az üres adatbázist, majd létrehozhatod az első admint.'
