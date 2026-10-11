param([string]$PhpPath = 'C:\laragon\bin\php\php-8.4.25-Win32-vs17-x64\php.exe')
$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
if (-not (Test-Path -LiteralPath $PhpPath)) { throw 'PHP tidak ditemukan. Gunakan parameter -PhpPath.' }
$demoDatabase = Join-Path $projectRoot '.runtime/gakin-demo.sqlite'
New-Item -ItemType Directory -Path (Join-Path $projectRoot '.runtime') -Force | Out-Null
if (-not (Test-Path -LiteralPath $demoDatabase)) { New-Item -ItemType File -Path $demoDatabase | Out-Null }
$demoVariables = @{APP_ENV='local'; APP_DEMO='true'; APP_URL='http://127.0.0.1:8001'; DB_CONNECTION='sqlite'; DB_DATABASE=$demoDatabase; DB_URL=''; SESSION_COOKIE='gakin_demo_session'; SESSION_DRIVER='file'; CACHE_STORE='array'; PANGI_ENABLED='false'; ADMIN_EMAIL=''; ADMIN_PASSWORD=''}
$previousVariables = @{}
Push-Location $projectRoot
try {
    foreach ($key in $demoVariables.Keys) {
        $previousVariables[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
        [Environment]::SetEnvironmentVariable($key, $demoVariables[$key], 'Process')
    }
    if (Test-Path -LiteralPath (Join-Path $projectRoot 'bootstrap/cache/config.php')) { throw 'Jalankan php artisan config:clear sebelum menjalankan demo.' }
    & $PhpPath artisan migrate --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Migrasi database demo gagal.' }
    & $PhpPath artisan db:seed --class=DemoSurveySeeder --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Pembuatan data contoh gagal.' }
    if (-not (Get-NetTCPConnection -State Listen -LocalPort 8001 -ErrorAction SilentlyContinue)) {
        Start-Process -FilePath $PhpPath -WorkingDirectory $projectRoot -ArgumentList @('artisan','serve','--host=127.0.0.1','--port=8001','--no-reload') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectRoot '.runtime/demo-web-stdout.log') -RedirectStandardError (Join-Path $projectRoot '.runtime/demo-web-stderr.log')
    }
    Write-Output 'Demo: http://127.0.0.1:8001 | Surveyor: DEMO001 / DEMO001 | Admin: DEMOADMIN / DEMOADMIN'
} finally {
    foreach ($key in $previousVariables.Keys) {
        if ($null -eq $previousVariables[$key]) { Remove-Item -LiteralPath ('Env:' + $key) -ErrorAction SilentlyContinue }
        else { [Environment]::SetEnvironmentVariable($key, $previousVariables[$key], 'Process') }
    }
    Pop-Location
}
