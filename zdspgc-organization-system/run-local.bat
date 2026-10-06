@echo off
rem =============================================================================
rem  ZDSPGC Organization Management System - one-command local launcher
rem
rem  Double-click this file (or run "run-local.bat" in a terminal) and it will:
rem    1. find PHP 8.1+ on this PC (PATH, XAMPP, Laragon or a portable copy)
rem    2. verify MySQL is running on 127.0.0.1:3306
rem    3. start the PHP dev server on the given port (default 8080)
rem    4. open install.php (first run) or login.php (already installed)
rem
rem  Usage:            run-local.bat [port]        (default port: 8080)
rem  Stop the server:  close the "ZDSPGC OrgSys - dev server" window
rem
rem  Optional env vars:
rem    ZDSPGC_PHP        full path to php.exe
rem    ZDSPGC_NO_BROWSER set to 1 to keep browser closed
rem    ZDSPGC_NO_PAUSE   set to 1 to skip the "press any key" prompt
rem =============================================================================
setlocal EnableExtensions
cd /d "%~dp0"
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "WORK=%TEMP%\zdspgc-orgsys-launcher"
if not exist "%WORK%" mkdir "%WORK%" >nul 2>nul

set "PORT=%~1"
if "%PORT%"=="" set "PORT=8080"

echo.
echo  ============================================================
echo   ZDSPGC Organization Management System - local launcher
echo  ============================================================
echo.

rem ---- 1. Find PHP 8.1+ -------------------------------------------------------
set "PHPBIN="
if defined ZDSPGC_PHP if exist "%ZDSPGC_PHP%" set "PHPBIN=%ZDSPGC_PHP%"
if not defined PHPBIN for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHPBIN set "PHPBIN=%%P"
if not defined PHPBIN for %%D in (C D E F) do if not defined PHPBIN if exist "%%D:\xampp\php\php.exe" set "PHPBIN=%%D:\xampp\php\php.exe"
if not defined PHPBIN for /d %%D in ("C:\laragon\bin\php\*") do if not defined PHPBIN if exist "%%~fD\php.exe" set "PHPBIN=%%~fD\php.exe"
if not defined PHPBIN goto :no_php

"%PHPBIN%" -r "echo PHP_VERSION_ID;" > "%WORK%\phpver.txt" 2>nul
set "PHPVID="
set /p PHPVID=<"%WORK%\phpver.txt"
if not defined PHPVID goto :no_php
if %PHPVID% LSS 80100 goto :old_php
echo  [1/3] PHP          %PHPBIN%

rem ---- 2. Check MySQL on 127.0.0.1:3306 --------------------------------------
echo  [2/3] MySQL        checking 127.0.0.1:3306 ...
call :port_state 127.0.0.1 3306
if /i "%PORTSTATE%"=="up" (
    echo        MySQL is running on 127.0.0.1:3306
    goto :start_server
)
set "MYSQLD="
set "MYSQLINI="
for %%D in (C D E F) do if not defined MYSQLD if exist "%%D:\xampp\mysql\bin\mysqld.exe" (
    set "MYSQLD=%%D:\xampp\mysql\bin\mysqld.exe"
    set "MYSQLINI=%%D:\xampp\mysql\bin\my.ini"
)
if not defined MYSQLD goto :no_mysqld
echo        starting MySQL from XAMPP ...
start "ZDSPGC MySQL (port 3306)" /min "%MYSQLD%" --defaults-file="%MYSQLINI%" --standalone
set /a TRIES=0
:wait_mysql
call :port_state 127.0.0.1 3306
if /i "%PORTSTATE%"=="up" (echo        MySQL is running. & goto :start_server)
set /a TRIES+=1
if %TRIES% GEQ 45 goto :mysql_timeout
>nul ping -n 2 127.0.0.1
goto :wait_mysql

rem ---- 3. Start the PHP dev server --------------------------------------------
:start_server
echo  [3/3] Web server   http://localhost:%PORT%
call :port_state 127.0.0.1 %PORT%
if /i "%PORTSTATE%"=="up" goto :check_existing
start "ZDSPGC OrgSys - dev server (port %PORT%)" "%PHPBIN%" -S 127.0.0.1:%PORT% -t "%ROOT%"
set /a TRIES=0
:wait_server
call :port_state 127.0.0.1 %PORT%
if /i "%PORTSTATE%"=="up" goto :server_ready
set /a TRIES+=1
if %TRIES% GEQ 20 goto :server_slow
>nul ping -n 2 127.0.0.1
goto :wait_server

:check_existing
"%PHPBIN%" -r "$c=@file_get_contents('http://127.0.0.1:%PORT%/login.php',false,stream_context_create(['http'=>['timeout'=>3]]));echo($c!==false&&strpos($c,'ZDSPGC')!==false)?'yes':'no';" > "%WORK%\app.txt" 2>nul
set "APPOK=no"
set /p APPOK=<"%WORK%\app.txt"
if /i "%APPOK%"=="yes" (echo        existing server reused & goto :server_ready)
goto :port_busy

:server_ready
"%PHPBIN%" -r "define('ZDSPGC_SKIP_INSTALL_CHECK',true);require 'config/database.php';require 'config/config.php';try{$p=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$ok=((int)$p->query(\"SELECT COUNT(*) FROM users WHERE role='admin'\")->fetchColumn())>0;}catch(Throwable $e){$ok=false;}echo $ok?'login':'install';" > "%WORK%\page.txt" 2>nul
set "OPENPAGE=install"
set /p OPENPAGE=<"%WORK%\page.txt"
if not "%ZDSPGC_NO_BROWSER%"=="1" start "" "http://localhost:%PORT%/%OPENPAGE%.php"
echo.
echo  ============================================================
echo   System is running:  http://localhost:%PORT%/
if "%OPENPAGE%"=="install" (
    echo   Opening:            install.php
    echo   Steps: 1) Install database  2) Create admin  3) Go to sign-in
) else (
    echo   Opening:            login.php  ^(system already installed^)
)
echo   Stop server: close "ZDSPGC OrgSys - dev server" window
echo  ============================================================
echo.
if not "%ZDSPGC_NO_PAUSE%"=="1" (echo  Press any key to close this launcher... & pause >nul)
exit /b 0

rem ---- errors -----------------------------------------------------------------
:no_php
echo  [X] PHP not found. Install XAMPP or set ZDSPGC_PHP to php.exe.
pause & exit /b 1
:old_php
echo  [X] PHP must be 8.1 or newer. Found: %PHPBIN%
pause & exit /b 1
:no_mysqld
echo  [X] MySQL is not running on port 3306 and mysqld.exe was not found.
echo      Start MySQL from the XAMPP Control Panel, then run this file again.
pause & exit /b 1
:mysql_timeout
echo  [X] MySQL did not start within 45 seconds. Check the minimized MySQL window.
pause & exit /b 1
:server_slow
echo  [!] Dev server slow to start. Open the server window to check for PHP errors.
goto :server_ready
:port_busy
echo  [!] Port %PORT% is used by another program.  Try:  run-local.bat 8081
pause & exit /b 1

rem ---- helper -----------------------------------------------------------------
:port_state
"%PHPBIN%" -r "echo @fsockopen('%1',(int)'%2',$e,$s,1)?'up':'down';" > "%WORK%\port.txt" 2>nul
set "PORTSTATE=down"
set /p PORTSTATE=<"%WORK%\port.txt"
exit /b 0
