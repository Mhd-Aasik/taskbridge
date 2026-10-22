$ErrorActionPreference = 'Stop'
$workspace = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
Set-Location -LiteralPath $workspace
$expected = [System.IO.Path]::GetFullPath((Join-Path $workspace 'storage/framework/testing/mysql-verification-20261022'))
$target = (Resolve-Path -LiteralPath $expected).Path
if ($target -ne $expected -or (Get-Content -LiteralPath (Join-Path $target 'owner.marker') -Raw).Trim() -ne 'taskbridge-disposable-mysql-verification-20261022') { throw 'Cleanup target/marker mismatch.' }
php tests/MySql/shutdown.php
if ($LASTEXITCODE -ne 0) { throw 'Shutdown identity validation failed; no files removed.' }
$until = (Get-Date).AddSeconds(30)
while (Get-NetTCPConnection -LocalPort 33079 -State Listen -ErrorAction SilentlyContinue) {
    if ((Get-Date) -gt $until) { throw 'Shutdown timeout; no files removed.' }
    Start-Sleep -Milliseconds 100
}
$serverId = [int](Get-Content -LiteralPath (Join-Path $target 'process.id'))
if (Get-Process -Id $serverId -ErrorAction SilentlyContinue) { Wait-Process -Id $serverId -Timeout 30 }
Remove-Item -LiteralPath $target -Recurse -Force
Write-Output 'Removed only the resolved marked disposable instance, fixtures, credentials and test files.'
