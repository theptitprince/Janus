@echo off
rem lancer_demo_banc.bat - Lance l'application de demonstration sur le banc d'essai
rem local (serveur php -S sur 127.0.0.1:8090, voir README.md). Double-cliquer.
rem ETDEL (c) 2026
cd /d "%~dp0"
python demo_appli.py --banc
if errorlevel 1 pause
