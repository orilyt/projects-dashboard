@echo off
rem  SPDX-License-Identifier: MIT  — open source, voir le fichier LICENSE.
rem ============================================================
rem  Ouvre/continue une session dans un projet (bouton "Claude").
rem  Appelé par index.php UNIQUEMENT si enable_launch=true (config).
rem    %1 = chemin WSL du projet (ex: "/mnt/c/laragon/www/foo")
rem    %2 = distro WSL          (ex: "Ubuntu")
rem    %3 = commande à lancer   (ex: "claude --continue || claude")
rem ============================================================

rem -- Primaire : Windows Terminal -> WSL -> commande
start "" wt.exe wsl.exe -d %2 --cd %1 -- bash -lic %3

rem -- Fallback sans Windows Terminal (fenetre console classique) :
rem    commente la ligne ci-dessus, decommente celle-ci.
rem start "" wsl.exe -d %2 --cd %1 -- bash -lic %3
