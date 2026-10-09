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
Write-Output 'Aplikasi: http://127.0.0.1:8000'
Write-Output 'Password awal tersedia di storage/app/private/AKSES-LOKAL.txt'
Write-Output 'Queue: php artisan queue:work --tries=3 --timeout=40'
