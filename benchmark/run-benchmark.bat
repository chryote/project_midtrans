@echo off
setlocal enabledelayedexpansion

echo ======================================================================
echo  Laravel Midtrans Scaffolding - Automated Benchmark Runner
echo ======================================================================

set TARGET_URL=http://localhost:8080
set CONCURRENCY=20
set REQUESTS=500

if not "%1"=="" set TARGET_URL=%1
if not "%2"=="" set CONCURRENCY=%2
if not "%3"=="" set REQUESTS=%3

echo Target Base URL : %TARGET_URL%
echo Concurrency     : %CONCURRENCY%
echo Total Requests  : %REQUESTS%
echo.

where k6 >nul 2>nul
if %errorlevel% equ 0 (
    echo [k6 detected] Running high-concurrency scenario test with k6...
    k6 run -e TARGET_URL=%TARGET_URL% benchmark\k6-benchmark.js
    goto :end
)

where php >nul 2>nul
if %errorlevel% equ 0 (
    echo [PHP CLI detected] Running concurrent benchmark runner...
    php benchmark\bench.php --url=%TARGET_URL%/api/health --requests=%REQUESTS% --concurrency=%CONCURRENCY%
    goto :end
)

where ab >nul 2>nul
if %errorlevel% equ 0 (
    echo [ApacheBench detected] Running ApacheBench (ab)...
    ab -n %REQUESTS% -c %CONCURRENCY% %TARGET_URL%/api/health
    goto :end
)

echo [Error] Neither k6, php, nor ab was found in system PATH.
echo Please install one of the above tools to execute load testing.

:end
echo.
echo Benchmark run finished.
