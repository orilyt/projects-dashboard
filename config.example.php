<?php
// SPDX-License-Identifier: MIT  — open source, voir le fichier LICENSE.
/**
 * Configuration du dashboard — MODÈLE.
 *
 * Copiez ce fichier en `config.php` et adaptez-le. `config.php` est ignoré par git
 * (chaque poste a le sien) ; s'il est absent, ce modèle sert de valeurs par défaut.
 *
 *   cp config.example.php config.php   (ou copie via l'explorateur)
 */

return [

    // Racine scannée : un dossier dont CHAQUE sous-dossier est un projet.
    // Par défaut, le dossier parent de ce dashboard (placez le dashboard dans
    // votre racine web et il scanne ses voisins). Mettez un chemin absolu pour
    // scanner ailleurs.  Windows : 'C:\\sites'   |  Linux/macOS : '/var/www'
    'root' => dirname(__DIR__),

    // Racines EXTERNES en plus (hors `root`), lues côté serveur par PHP : ces
    // dossiers ne sont JAMAIS servis en HTTP (le serveur ne sert que le docroot),
    // donc on peut afficher un workspace hors-web sans l'exposer. Affichées avec
    // un badge "externe" et sans lien d'ouverture.  [] = aucune.
    // Ex : ['C:\\Backups', '/home/me/ops']
    'extra_roots' => [],

    // Dossiers de `root` à ignorer (infra, pas des projets).
    'exclude' => ['projets', 'vendor', 'test', '.git'],

    // Tout dossier commençant par ce préfixe est ignoré (backups, brouillons…).
    'exclude_prefix' => '_',

    // ----------------------------------------------------------------
    //  Bouton "Claude" — OUVRE UN TERMINAL LOCAL via exec(). ⚠️ DANGER.
    // ----------------------------------------------------------------
    // À NE PASSER À true QUE sur un poste local mono-utilisateur, servi en
    // loopback (127.0.0.1). Activé, c'est un endpoint qui exécute une commande
    // système : ne JAMAIS l'exposer sur un réseau / host partagé. Voir README §Sécurité.
    // Spécifique Windows + WSL (s'appuie sur wt.exe / wsl.exe / launch-claude.bat).
    'enable_launch' => false,

    // Réglages du lancement (ignorés si enable_launch = false).
    'launch' => [
        'wsl_distro' => 'Ubuntu',                 // nom de la distro : `wsl -l -q`
        'command'    => 'claude --continue || claude', // exécutée dans le dossier du projet
    ],

    // Perf : plafond de fichiers scannés par projet pour estimer la dernière activité.
    'scan_file_cap' => 4000,

    // Dossiers jamais traversés pendant ce scan (lourds / inutiles).
    'skip_dirs' => ['vendor', 'node_modules', '.git', '.svn', 'cache', 'tmp', 'storage'],
];
