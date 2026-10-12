param(
    [string]$PhpPath = 'C:\laragon\bin\php\php-8.4.25-Win32-vs17-x64\php.exe',
    [string]$ComposerPath = 'C:\laragon\bin\composer\composer.phar',
    [string]$NpmPath = 'C:\laragon\bin\nodejs\node-v22\npm.cmd'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$releaseId = (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
$buildRoot = Join-Path $projectRoot ('.runtime/cpanel-build-' + $releaseId)
$packageRoot = Join-Path $buildRoot 'survey-gakin'
$releaseRoot = Join-Path $projectRoot '.runtime/releases'
$archivePath = Join-Path $releaseRoot ('survey-gakin-cpanel-' + $releaseId + '.zip')

foreach ($binary in @($PhpPath, $ComposerPath, $NpmPath)) {
    if (-not (Test-Path -LiteralPath $binary -PathType Leaf)) {
        throw "Program tidak ditemukan: $binary. Sesuaikan parameter script."
    }
}
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'node_modules') -PathType Container)) {
    throw 'Jalankan npm ci --ignore-scripts di project sebelum membuat paket.'
}
New-Item -ItemType Directory -Path $packageRoot, $releaseRoot -Force | Out-Null

function Copy-ReleaseFile {
    param([Parameter(Mandatory)][string]$RelativePath)

    $sourcePath = Join-Path $projectRoot $RelativePath
    $source = Get-Item -LiteralPath $sourcePath -Force
    if ($source.PSIsContainer -or ($source.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "Paket hanya menerima file biasa: $RelativePath"
    }
    $destinationPath = Join-Path $packageRoot $RelativePath
    $destinationDirectory = Split-Path -Parent $destinationPath
    if (-not (Test-Path -LiteralPath $destinationDirectory)) {
        New-Item -ItemType Directory -Path $destinationDirectory | Out-Null
    }
    Copy-Item -LiteralPath $sourcePath -Destination $destinationPath
}

function Copy-ReleaseTree {
    param([Parameter(Mandatory)][string]$RelativePath)

    $source = Get-Item -LiteralPath (Join-Path $projectRoot $RelativePath) -Force
    if (-not $source.PSIsContainer -or ($source.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "Folder paket tidak boleh berupa symlink/junction: $RelativePath"
    }
    foreach ($item in Get-ChildItem -LiteralPath $source.FullName -Force) {
        $relativeItem = Join-Path $RelativePath $item.Name
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw "Symlink/junction tidak dimasukkan dalam paket: $relativeItem"
        }
        if ($item.PSIsContainer) {
            Copy-ReleaseTree -RelativePath $relativeItem
        } else {
            Copy-ReleaseFile -RelativePath $relativeItem
        }
    }
}

$previousPath = $env:Path
Push-Location $projectRoot
try {
    $env:Path = (Split-Path -Parent $NpmPath) + [IO.Path]::PathSeparator + $previousPath
    Write-Output 'Membangun aset frontend...'
    & $NpmPath run build *> (Join-Path $buildRoot 'npm-build.log')
    if ($LASTEXITCODE -ne 0) { throw "Build frontend gagal. Lihat $buildRoot/npm-build.log" }
} finally {
    $env:Path = $previousPath
    Pop-Location
}

$files = @(
    'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'vite.config.js', '.npmrc', '.env.cpanel.example', 'README.md', 'LICENSE',
    'bootstrap/app.php', 'bootstrap/providers.php',
    'public/.htaccess', 'public/index.php', 'public/favicon.ico',
    'public/manifest.webmanifest', 'public/robots.txt', 'public/sw.js',
    'public/form-letterhead.png', 'public/form-logo.png',
    'tools/deploy-cpanel.sh', 'tools/prepare-cpanel.ps1'
)
foreach ($file in $files) { Copy-ReleaseFile -RelativePath $file }
$trees = @('app', 'config', 'routes', 'resources', 'database/migrations', 'database/seeders', 'database/factories', 'public/brand', 'public/build')
foreach ($tree in $trees) { Copy-ReleaseTree -RelativePath $tree }

$writableDirectories = @('bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs')
foreach ($directory in $writableDirectories) {
    New-Item -ItemType Directory -Path (Join-Path $packageRoot $directory) -Force | Out-Null
}

$buildVariables = @{
    APP_ENV = 'production'; APP_DEBUG = 'false'; APP_DEMO = 'false'; APP_KEY = ''
    APP_URL = 'https://gakin.poltekkes-manado.ac.id'; APP_BASE_PATH = $packageRoot
    APP_CONFIG_CACHE = 'bootstrap/cache/config.php'
    APP_EVENTS_CACHE = 'bootstrap/cache/events.php'
    APP_ROUTES_CACHE = 'bootstrap/cache/routes-v7.php'
    APP_SERVICES_CACHE = 'bootstrap/cache/services.php'
    APP_PACKAGES_CACHE = 'bootstrap/cache/packages.php'
    LARAVEL_STORAGE_PATH = (Join-Path $packageRoot 'storage')
    DB_CONNECTION = 'sqlite'; DB_DATABASE = ':memory:'; DB_URL = ''
    SESSION_DRIVER = 'array'; CACHE_STORE = 'array'; QUEUE_CONNECTION = 'sync'
    LOG_CHANNEL = 'stderr'; PANGI_ENABLED = 'false'; PANGI_AUTO_PREPARE = 'false'
    ADMIN_EMAIL = ''; ADMIN_PASSWORD = ''
    COMPOSER = (Join-Path $packageRoot 'composer.json')
    COMPOSER_VENDOR_DIR = (Join-Path $packageRoot 'vendor')
}
$previousVariables = @{}
Push-Location $packageRoot
try {
    foreach ($name in $buildVariables.Keys) {
        $previousVariables[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $buildVariables[$name], 'Process')
    }
    Write-Output 'Memasang vendor produksi dari composer.lock pada staging terpisah...'
    & $PhpPath $ComposerPath install --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction --no-progress *> (Join-Path $buildRoot 'composer-install.log')
    if ($LASTEXITCODE -ne 0) { throw "Composer gagal. Lihat $buildRoot/composer-install.log" }
    & $PhpPath artisan package:discover --no-interaction *> (Join-Path $buildRoot 'package-discover.log')
    if ($LASTEXITCODE -ne 0) { throw "Package discovery gagal. Lihat $buildRoot/package-discover.log" }
    if ((Get-FileHash -LiteralPath 'composer.lock' -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath (Join-Path $projectRoot 'composer.lock') -Algorithm SHA256).Hash) {
        throw 'composer.lock staging berubah; paket tidak dibuat.'
    }
} finally {
    foreach ($name in $previousVariables.Keys) {
        if ($null -eq $previousVariables[$name]) {
            Remove-Item -LiteralPath ('Env:' + $name) -ErrorAction SilentlyContinue
        } else {
            [Environment]::SetEnvironmentVariable($name, $previousVariables[$name], 'Process')
        }
    }
    Pop-Location
}

if (Test-Path -LiteralPath (Join-Path $packageRoot '.env')) { throw 'Paket tidak boleh berisi .env aktif.' }
if (-not (Test-Path -LiteralPath (Join-Path $packageRoot 'vendor/autoload.php'))) { throw 'Vendor produksi tidak lengkap.' }
if (-not (Test-Path -LiteralPath (Join-Path $packageRoot 'public/build/manifest.json'))) { throw 'Manifest frontend tidak tersedia.' }

Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::Open($archivePath, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($directory in $writableDirectories) {
        $archive.CreateEntry('survey-gakin/' + $directory + '/') | Out-Null
    }
    foreach ($file in Get-ChildItem -LiteralPath $packageRoot -Recurse -Force -File | Sort-Object FullName) {
        $relativePath = $file.FullName.Substring($packageRoot.Length + 1).Replace('\', '/')
        if ($relativePath.StartsWith('storage/') -or $relativePath.StartsWith('bootstrap/cache/')) { continue }
        if ($relativePath -match '(^|/)(\.env|auth\.json)$|^public/hot$|^public/storage/|^tests/|^node_modules/|^\.runtime/|^\.git/') {
            throw "File terlarang ditemukan pada staging: $relativePath"
        }
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, ('survey-gakin/' + $relativePath), [IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally {
    $archive.Dispose()
}
$archiveHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText($archivePath + '.sha256', $archiveHash + '  ' + [IO.Path]::GetFileName($archivePath) + "`n", [Text.UTF8Encoding]::new($false))
Write-Output "Paket: $archivePath"
Write-Output "SHA256: $archiveHash"
Write-Output "Staging dan log disimpan: $buildRoot"
Write-Output 'Paket kode tidak berisi database, .env aktif, atau bukti survey. Pindahkan data secara terpisah mengikuti README.md.'
