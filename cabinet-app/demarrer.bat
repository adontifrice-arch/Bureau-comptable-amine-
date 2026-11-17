@echo off
cd /d "%~dp0"
start "" http://127.0.0.1:8000/connexion
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan serve --host=127.0.0.1 --port=8000
pause
