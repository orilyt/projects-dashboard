# Tutoriel — installer le dashboard et l'adapter à vos projets

[English](TUTO.md) · **Français**

Objectif : passer d'un serveur local vierge à un dashboard qui liste **vos** projets,
en ~10 minutes. Aucun prérequis au-delà de PHP et d'un serveur local.

> Pour la référence complète des options, voir [README.fr.md](README.fr.md). Ici, on déroule.

---

## Étape 0 — Avoir un serveur local

Choisissez **une** option :

| Outil | Comment servir le dossier |
|---|---|
| **Laragon** (Windows) | Placez le dossier dans `C:\laragon\www\`. Auto-vhost `http://projets.test`. |
| **XAMPP / WAMP / MAMP** | Placez le dossier dans `htdocs/` (ou `www/`). `http://localhost/projets/`. |
| **PHP seul** (tout OS) | Depuis le dossier : `php -S localhost:8000` → `http://localhost:8000`. |

Vérifiez que PHP est en **8.0+** : `php -v`.

---

## Étape 1 — Récupérer les fichiers

Clonez ou copiez ce dossier `projets/` **dans votre racine web, à côté de vos sites** :

```
www/                 (ou htdocs/, ou le dossier que vous servez)
├── projets/         ← le dashboard
├── client-alpha/    ← vos projets…
├── boutique-beta/
└── api-gamma/
```

La logique : le dashboard scanne son **dossier parent** et traite chaque sous-dossier
comme un projet. Placé dans `www/`, il liste tout `www/`.

---

## Étape 2 — Créer votre configuration

```bash
cd projets
cp config.example.php config.php      # Windows : copy config.example.php config.php
```

`config.php` est **à vous** (ignoré par git). Ouvrez-le : les valeurs par défaut
conviennent pour « scanner le dossier parent ». On l'ajuste à l'étape 4.

---

## Étape 3 — Ouvrir le dashboard

- Laragon / vhost : `http://projets.test`
- XAMPP & co : `http://localhost/projets/`
- `php -S` : `http://localhost:8000`

Vous devez voir la liste de vos dossiers, avec leur activité git et un statut présumé.
Si la page est blanche : voir [Dépannage](#dépannage).

---

## Étape 4 — Adapter le scan à votre arborescence

Dans `config.php` :

- **Scanner un autre dossier** que le parent :
  ```php
  'root' => 'C:\\sites',        // Windows
  'root' => '/var/www',         // Linux/macOS
  ```
- **Cacher des dossiers** qui ne sont pas des projets :
  ```php
  'exclude'        => ['projets', 'vendor', '_archives', 'phpmyadmin'],
  'exclude_prefix' => '_',      // cache aussi _backups, _drafts, …
  ```
- **Afficher un dossier hors racine web** (lu côté serveur, jamais exposé en HTTP) :
  ```php
  'extra_roots' => ['C:\\Backups\\ops'],
  ```

Rechargez la page après chaque changement.

---

## Étape 5 — Donner un avancement « métier » à un projet

Le dashboard devine l'activité, mais **vous** seul savez où en est un projet. Ajoutez un
`STATUS.md` à la racine **du projet** (pas du dashboard) :

```markdown
---
status: en cours
progress: 45
next: Brancher le paiement Stripe
updated: 2026-05-31
---
```

Rechargez : la ligne du projet affiche maintenant votre statut, la barre à 45 %, et la
prochaine étape. Copiez [`STATUS.md.example`](STATUS.md.example) comme point de départ.

Projet à plusieurs fronts ? Laissez `status`/`progress` vides et ajoutez un tableau
`## Chantiers` (voir README) — le dashboard fait la moyenne.

---

## Étape 6 (optionnel, avancé) — Le bouton « Claude » / commande locale

> ⚠️ **Lisez la section Sécurité du README avant.** Ceci active un endpoint qui
> **exécute une commande système**. À réserver à un **poste local mono-utilisateur**
> servi en `127.0.0.1`. Jamais sur un réseau / host partagé. Windows + WSL.

1. Dans `config.php` :
   ```php
   'enable_launch' => true,
   'launch' => [
       'wsl_distro' => 'Ubuntu',                  // votre distro : wsl -l -q
       'command'    => 'claude --continue || claude', // ou 'code .', 'git status', …
   ],
   ```
2. Rechargez. Un bouton apparaît sur chaque ligne ; un clic ouvre Windows Terminal dans
   le dossier du projet et lance votre commande.
3. La commande est libre : remplacez-la par ce que vous voulez exécuter au lancement
   d'un projet (éditeur, shell, script de setup…).

**Linux / macOS** : le mécanisme fourni s'appuie sur `wt.exe` / `wsl.exe` /
`launch-claude.bat` (Windows). Il est **adaptable** (terminal natif via
`gnome-terminal`/`osascript`, en éditant le handler `launch` d'`index.php`) — voir le
README, section « Sur Linux / macOS ». **Non testé par l'auteur.** En attendant, laissez
`enable_launch => false` ; le reste du dashboard fonctionne partout.

---

## Étape 6b (optionnel) — Le bouton « Nouveau projet »

> ⚠️ Mêmes réserves qu'à l'étape 6 : **poste local mono-utilisateur, loopback only.** Ceci
> active un endpoint qui crée un dossier (`mkdir`) sous `root` à partir d'un nom saisi.

1. Dans `config.php` : `'enable_create' => true,`
2. Rechargez. Un bouton **« + Nouveau projet »** apparaît dans la barre d'outils.
3. Cliquez, saisissez un nom (lettres, chiffres, `. _ -`). Le dossier est créé sous `root`.
   Si `enable_launch` est aussi `true`, Claude s'ouvre sur le dossier ; sinon le dossier est
   simplement créé et la page recharge.

Le nom est sanitisé côté serveur (`^[A-Za-z0-9][A-Za-z0-9._-]*$`) et confiné à `root` via un
contrôle `realpath` — le `mkdir` ne peut pas sortir du dossier scanné.

---

## Dépannage

| Symptôme | Piste |
|---|---|
| **Page blanche** | PHP < 8.0, ou `config.php` mal copié. Vérifiez `php -v` ; regardez les logs PHP / activez `display_errors`. |
| **Aucun projet listé** | `root` pointe au mauvais endroit, ou tout est dans `exclude`. |
| **Un dossier manque** | Il commence par `exclude_prefix` (`_`) ou est dans `exclude`. |
| **Bouton Claude : `forbidden`** | Normal hors loopback ou sans rechargement (jeton CSRF). Servez en `127.0.0.1` et rechargez. |
| **Bouton Claude : `launch désactivé`** | `enable_launch` est `false` dans `config.php`. |
| **Bouton Claude : aucune fenêtre** | Le serveur ne peut pas ouvrir de fenêtre GUI (session non interactive). Voir le fallback console dans `launch-claude.bat`. |
| **Pas de bouton « Nouveau projet »** | `enable_create` est `false` (ou absent) dans `config.php`. |
| **Nouveau projet : `nom invalide`** | Le nom contient des caractères hors `A-Z a-z 0-9 . _ -` (pas de `/`, `\`, espace). |

---

## Comprendre le code en 30 secondes (pour étendre)

`index.php` est **un seul fichier** dans cet ordre :

1. **Config** : charge `config.php`.
2. **Endpoints launch / create** : court-circuit si requête POST `launch` ou `create` (sinon ignoré).
3. **Helpers** : `scanActivity()`, `gitInfo()`, `statusFile()`, etc.
4. **Collecte** : `foreach` sur les dossiers → tableau `$projects`.
5. **Rendu HTML** + **CSS inline** (`:root` pour les couleurs) + **JS** (filtre/tri).

Pour ajouter une colonne : ajoutez la donnée dans la collecte, une cellule dans le rendu,
et au besoin une entête dans `.thead`. Pas de framework, pas de build — éditez, rechargez.

---

## Licence

Ce projet est **open source sous licence [MIT](LICENSE)** : vous pouvez l'utiliser, le
modifier et le redistribuer librement (y compris dans un cadre commercial), en conservant
la mention de copyright. Adaptez-le sans contrainte à vos besoins.
