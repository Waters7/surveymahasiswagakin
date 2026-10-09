$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$php = 'C:\laragon\bin\php\php-8.4.25-Win32-vs17-x64\php.exe'
$mysql = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
if (-not (Test-Path -LiteralPath $php)) { throw 'Sesuaikan path PHP Laragon pada start-local.ps1.' }
if (-not (Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath $mysql -WorkingDirectory $projectRoot -ArgumentList @('--no-defaults','--basedir=C:/laragon/bin/mysql/mysql-8.4.3-winx64',('--datadir="' + ($projectRoot.Replace('\','/')) + '/.runtime/mysql"'),'--port=3307','--bind-address=127.0.0.1','--mysqlx=0','--console') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectRoot '.runtime/mysql-stdout.log') -RedirectStandardError (Join-Path $projectRoot '.runtime/mysql-stderr.log')
}
if (-not (Get-NetTCPConnection -State Listen -LocalPort 8000 -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath $php -WorkingDirectory $projectRoot -ArgumentList @('artisan','serve','--host=127.0.0.1','--port=8000') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectRoot '.runtime/web-stdout.log') -RedirectStandardError (Join-Path $projectRoot '.runtime/web-stderr.log')
}
$schedulerPidFile = Join-Path $projectRoot '.runtime/scheduler.pid'
$schedulerRunning = $false
if (Test-Path -LiteralPath $schedulerPidFile) {
    $schedulerProcessId = 0
    if ([int]::TryParse((Get-Content -LiteralPath $schedulerPidFile -Raw).Trim(), [ref]$schedulerProcessId)) {
        $schedulerProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $schedulerProcessId" -ErrorAction SilentlyContinue
        $schedulerRunning = $schedulerProcess -and $schedulerProcess.ExecutablePath -eq $php -and $schedulerProcess.CommandLine.Contains((Join-Path $projectRoot 'artisan')) -and $schedulerProcess.CommandLine.Contains('schedule:work')
    }
}
if (-not $schedulerRunning) {
    $schedulerProcess = Start-Process -FilePath $php -WorkingDirectory $projectRoot -ArgumentList @(('"' + (Join-Path $projectRoot 'artisan') + '"'),'schedule:work','--no-interaction') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $projectRoot '.runtime/scheduler-stdout.log') -RedirectStandardError (Join-Path $projectRoot '.runtime/scheduler-stderr.log')
    $schedulerProcess.Id | Set-Content -LiteralPath $schedulerPidFile
}
Write-Output 'Scheduler pengingat berjalan selama proses lokal aktif. Jadwal: setiap hari 16.00 WITA.'
Write-Output 'Aplikasi: http://127.0.0.1:8000'
Write-Output 'Password awal tersedia di storage/app/private/AKSES-LOKAL.txt'
Write-Output 'Queue: php artisan queue:work --tries=3 --timeout=40'
