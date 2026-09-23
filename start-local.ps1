param(
    [int]$Port = 8088
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$publicDir = Join-Path $root 'public'
$routerFile = Join-Path $publicDir 'router.php'
$initFile = Join-Path $root 'scripts\init-local.php'
$dbPath = Join-Path $root 'var\local.sqlite'

if (-not (Test-Path -LiteralPath $dbPath)) {
    & php $initFile
    if ($LASTEXITCODE -ne 0) {
        throw 'Local database initialization failed.'
    }
}

$env:XARR_DB_DSN = 'sqlite:' + $dbPath
$env:XARR_PLUGIN_DIR = Join-Path $root 'plugins'

& php '-S' ('127.0.0.1:' + $Port) '-t' $publicDir $routerFile
