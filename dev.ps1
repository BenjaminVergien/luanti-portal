param(
    [ValidateSet('start', 'artisan', 'composer', 'build', 'test', 'bridge')]
    [string]$Task = 'start',
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$ToolArguments
)

$ErrorActionPreference = 'Stop'
$toolsDirectory = Join-Path (Split-Path $PSScriptRoot -Parent) '.dev-tools'
$phpExecutable = Join-Path $toolsDirectory 'php\php.exe'
$composerExecutable = Join-Path $toolsDirectory 'composer.phar'

if (-not (Test-Path -LiteralPath $phpExecutable)) {
    $phpExecutable = (Get-Command php -ErrorAction Stop).Source
}
if (-not (Test-Path -LiteralPath $composerExecutable)) {
    throw 'Composer fehlt. Die lokale Toolchain liegt im benachbarten Ordner .dev-tools.'
}

$previousPath = $env:PATH
$previousComposerHome = $env:COMPOSER_HOME
$previousComposerCache = $env:COMPOSER_CACHE_DIR
$bridgeToolsDirectory = Join-Path $PSScriptRoot 'storage\app\private\bridge-development\tools'
$env:PATH = (Split-Path $phpExecutable -Parent) + ';' + $bridgeToolsDirectory + ';' + $env:PATH
$env:COMPOSER_HOME = Join-Path $toolsDirectory 'composer-home'
$env:COMPOSER_CACHE_DIR = Join-Path $toolsDirectory 'composer-cache'

Push-Location $PSScriptRoot
try {
    switch ($Task) {
        'start' { & $phpExecutable $composerExecutable run dev }
        'artisan' { & $phpExecutable artisan @ToolArguments }
        'composer' { & $phpExecutable $composerExecutable @ToolArguments }
        'build' { & npm.cmd run build }
        'test' { & $phpExecutable $composerExecutable run test }
        'bridge' { & $phpExecutable artisan luanti:bridge:dev @ToolArguments }
    }
    $result = $LASTEXITCODE
} finally {
    Pop-Location
    $env:PATH = $previousPath
    $env:COMPOSER_HOME = $previousComposerHome
    $env:COMPOSER_CACHE_DIR = $previousComposerCache
}
exit $result
