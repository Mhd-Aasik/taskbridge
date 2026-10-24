param(
    [Parameter(Mandatory = $true)][string]$Destination,
    [string]$Ref = 'HEAD'
)
$ErrorActionPreference = 'Stop'
$sourceRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$buildRoot = [IO.Path]::GetFullPath($Destination)
if (Test-Path -LiteralPath $buildRoot) { throw 'Destination must be new; no existing directory will be overwritten' }
if ($buildRoot.TrimEnd('\', '/') -eq $sourceRoot.TrimEnd('\', '/')) { throw 'Cannot build into the source checkout' }
# A committed snapshot excludes local secrets, private uploads and uncommitted work.
$commit = (& git -c "safe.directory=$sourceRoot" -C $sourceRoot rev-parse --verify "$Ref^{commit}").Trim()
if ($LASTEXITCODE -ne 0) { throw 'Unknown commit' }
New-Item -ItemType Directory -Path $buildRoot | Out-Null
Set-Content -LiteralPath (Join-Path $buildRoot 'taskbridge-build.marker') $commit
$workRoot = Join-Path $buildRoot 'work'
$releaseRoot = Join-Path $buildRoot 'release'
New-Item -ItemType Directory -Path $workRoot, $releaseRoot | Out-Null
$archive = Join-Path $buildRoot 'source.tar'
& git -c "safe.directory=$sourceRoot" -C $sourceRoot archive --format=tar "--output=$archive" $commit
if ($LASTEXITCODE -ne 0) { throw 'Source archive failed' }
& tar -xf $archive -C $workRoot
if ($LASTEXITCODE -ne 0) { throw 'Source extraction failed' }
& git -C $workRoot init --quiet
if ($LASTEXITCODE -ne 0) { throw 'Build source boundary failed' }

$names = @('APP_ENV','APP_KEY','APP_DEBUG','APP_URL','DB_CONNECTION','DB_DATABASE','DB_URL','DATABASE_URL','MAIL_MAILER','SESSION_DRIVER','CACHE_STORE','QUEUE_CONNECTION','VITE_APP_NAME','APP_CONFIG_CACHE','APP_ROUTES_CACHE','VIEW_COMPILED_PATH','LARAVEL_STORAGE_PATH')
$saved = @{}
foreach ($name in $names) { $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
$previous = Get-Location
try {
    # Package discovery/build cannot select the running application's configuration.
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $null, 'Process') }
    $env:APP_ENV = 'production'
    $env:APP_DEBUG = 'false'
    $env:APP_URL = 'https://build.example.test'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = ':memory:'
    $env:MAIL_MAILER = 'array'
    $env:SESSION_DRIVER = 'array'
    $env:CACHE_STORE = 'array'
    $env:QUEUE_CONNECTION = 'sync'
    $env:VITE_APP_NAME = 'TaskBridge'
    Set-Location $workRoot
    & composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    if ($LASTEXITCODE -ne 0) { throw 'Production dependencies failed' }
    & composer check-platform-reqs --no-dev
    if ($LASTEXITCODE -ne 0) { throw 'Production platform requirements failed' }
    & npm.cmd ci --no-audit --no-fund --fetch-retries=1 --fetch-timeout=20000
    if ($LASTEXITCODE -ne 0) { throw 'Locked frontend installation failed' }
    & npm.cmd run build
    if ($LASTEXITCODE -ne 0) { throw 'Production frontend build failed' }
    foreach ($name in @('app','bootstrap','config','database','public','resources','routes','vendor','deployment','artisan','composer.json','composer.lock')) {
        Copy-Item -LiteralPath (Join-Path $workRoot $name) -Destination $releaseRoot -Recurse
    }
    foreach ($path in @('app/private','framework/cache/data','framework/sessions','framework/views','logs')) {
        New-Item -ItemType Directory -Path (Join-Path $releaseRoot "storage/$path") -Force | Out-Null
    }
    $manifest = Get-Content -LiteralPath (Join-Path $releaseRoot 'public/build/manifest.json') -Raw | ConvertFrom-Json
    if (-not $manifest.'resources/css/app.css') { throw 'Missing CSS entry' }
    foreach ($entry in $manifest.PSObject.Properties) {
        $asset = Get-Item -LiteralPath (Join-Path $releaseRoot ('public/build/' + $entry.Value.file))
        if ($asset.Length -eq 0) { throw 'Empty manifest asset' }
    }
    foreach ($forbidden in @('.env','.git','node_modules','tests','public/hot','public/storage')) {
        if (Test-Path -LiteralPath (Join-Path $releaseRoot $forbidden)) { throw "Unexpected runtime payload: $forbidden" }
    }
    Set-Content -LiteralPath (Join-Path $releaseRoot 'RELEASE_COMMIT') $commit
    $files = @(Get-ChildItem -LiteralPath $releaseRoot -Recurse -File -Force)
    $measurement = [ordered]@{ commit = $commit; files = $files.Count; bytes = ($files | Measure-Object Length -Sum).Sum; includes = 'production vendor, compiled assets and application; no secrets or user data' }
    $measurement | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $buildRoot 'release-measurement.json')
    $measurement | ConvertTo-Json
    Write-Output "Prepared locally: $releaseRoot"
} finally {
    Set-Location $previous
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process') }
}
# Work/cache directories are retained for inspection. No upload or remote change occurs.
