@echo off
REM ============================================================
REM LDB-FRAS - Complete Service Startup
REM Starts both face recognition service and keep-alive monitor
REM ============================================================

title LDB-FRAS Service Manager

echo ============================================================
echo  LDB-FRAS Face Recognition System
echo ============================================================
echo.

REM Check if Python is available
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python is not installed or not in PATH
    echo Please install Python 3.8+ and add to PATH
    pause
    exit /b 1
)

REM Change to python directory (go up one level from deploy)
cd /d "%~dp0..\python"

echo Starting Face Recognition Service...
echo.

REM Start the main service in a new window
start "LDB-FRAS Service" cmd /k "python app.py"

REM Wait for service to initialize
echo Waiting for service to start (15 seconds)...
timeout /t 15 /nobreak >nul

REM Start keep-alive monitor in background
echo Starting Keep-Alive Monitor...
start "LDB-FRAS Keep-Alive" /min cmd /k "python keepalive.py"

echo.
echo ============================================================
echo  Services Started Successfully!
echo ============================================================
echo.
echo  Main Service: http://localhost:5000
echo  Health Check: http://localhost:5000/api/health
echo.
echo  Close this window to stop all services
echo  Or press Ctrl+C in service windows
echo ============================================================
echo.

REM Keep this window open
pause
