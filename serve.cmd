@echo off
rem ローカル開発用の簡易サーバー（本番は Apache + DocumentRoot=public を使うこと）
setlocal
if "%PHP_BIN%"=="" set PHP_BIN=C:\xampp\php\php.exe
if not exist "%PHP_BIN%" set PHP_BIN=php
echo http://127.0.0.1:8080/ で起動します（停止は Ctrl+C）
"%PHP_BIN%" -S 127.0.0.1:8080 -t "%~dp0public" "%~dp0bin\router.php"
endlocal
