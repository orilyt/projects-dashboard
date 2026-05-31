---
status: en cours
progress: 90
next: Choisir le mode de livraison (git archive, sans historique ni config.php) et une capture d'aperçu neutre
updated: 2026-05-31
---

# projets — Tableau de bord d'avancement

Dashboard PHP pur (zéro dépendance) qui scanne un dossier de projets, déduit l'activité
(git + mtime) et lit un `STATUS.md` optionnel par projet pour afficher un avancement
« métier ». Open source (MIT). Voir `README.md` / `TUTO.md`.

## État

- **Fonctionnel et committé.** Scan + parsing frontmatter + tableau `## Chantiers`,
  agrégation statut/progress, UI dark, filtres/tri JS.
- **Vue tableau** : colonnes alignées (Projet · Statut · Avancement · Activité · Actions)
  + en-tête sticky ; champs longs renvoyés sur une sous-ligne pleine largeur.
- **Racines externes** (`extra_roots`) : afficher des dossiers hors racine web, lus côté
  serveur, jamais servis en HTTP.
- **Bouton « Claude »** optionnel (Windows + WSL), **OFF par défaut** : endpoint `exec`
  protégé (POST + token CSRF + loopback). Voir README §Sécurité.
- **Mise en partage** : config externalisée (`config.example.php` ; `config.php` local
  gitignoré), docs `README.md` + `TUTO.md`, licence MIT.

## À faire

- `progress` 90 = ressenti : reste la mise en distribution.
- Livrer via `git archive` (arbre propre, sans `.git` ni `config.php`) plutôt qu'une
  copie de dossier ; ajouter une capture d'aperçu neutre.
