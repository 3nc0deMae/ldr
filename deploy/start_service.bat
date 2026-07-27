@echo off
REM ============================================================
REM LDB-FRAS - Face Recognition Service Runner
REM Keeps the Python service running persistently
REM ============================================================

title LDB-FRAS Face Recognition Service

:START
echo [%date% %time%] Starting Face Recognition Service...
echo.

REM Change to the python directory (go up one level from deploy)
cd /d "%~dp0..\python"

REM Run the Python app
python app.py

REM If app crashes, wait 5 seconds and restart
echo.
echo [%date% %time%] Service stopped unexpectedly. Restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto START
