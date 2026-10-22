$ErrorActionPreference = 'Stop'
$workspace = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
Set-Location -LiteralPath $workspace
if ($env:DB_URL -or $env:DATABASE_URL) { throw 'Database URL overrides are forbidden.' }
$taskRoot = Join-Path $workspace 'storage/framework/testing/mysql-verification-20261022'
if (Test-Path -LiteralPath $taskRoot) { throw 'Disposable directory already exists; refusing reuse.' }
if (Get-NetTCPConnection -LocalPort 33079 -State Listen -ErrorAction SilentlyContinue) { throw 'Disposable port occupied.' }
$mysqlBase = 'C:\Program Files\MySQL\MySQL Server 8.0'
New-Item -ItemType Directory -Path $taskRoot | Out-Null
Set-Content -LiteralPath (Join-Path $taskRoot 'owner.marker') 'taskbridge-disposable-mysql-verification-20261022'
& (Join-Path $mysqlBase 'bin/mysqld.exe') --no-defaults --initialize-insecure "--basedir=$mysqlBase" "--datadir=$taskRoot/data" "--log-error=$taskRoot/initialize.log"
if ($LASTEXITCODE -ne 0) { throw 'Isolated initialization failed; inspect marked directory.' }
$server = Start-Process -FilePath (Join-Path $mysqlBase 'bin/mysqld.exe') -ArgumentList @('--no-defaults',('--basedir="'+$mysqlBase+'"'),('--datadir="'+$taskRoot+'/data"'),'--port=33079','--bind-address=127.0.0.1','--mysqlx=OFF',('--log-error="'+$taskRoot+'/server.log"'),('--pid-file="'+$taskRoot+'/server.pid"')) -WindowStyle Hidden -PassThru
Set-Content -LiteralPath (Join-Path $taskRoot 'process.id') $server.Id
$until = (Get-Date).AddSeconds(30)
do {
    if ($server.HasExited) { throw 'Disposable server exited.' }
    $ready = Select-String -LiteralPath (Join-Path $taskRoot 'server.log') -Pattern 'ready for connections' -Quiet -ErrorAction SilentlyContinue
    if ((Get-Date) -gt $until) { throw 'Server readiness timeout.' }
    if (-not $ready) { Start-Sleep -Milliseconds 100 }
} while (-not $ready)
php tests/MySql/provision.php
if ($LASTEXITCODE -ne 0) { throw 'Guarded provisioning failed.' }
