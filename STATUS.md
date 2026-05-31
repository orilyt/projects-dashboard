---
status: en cours
progress: 90
next: Régler docs/preview.png en social preview GitHub (Settings → Social preview) ; promo communautés (r/PHP…)
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
- **Publié** : repo public github.com/orilyt/projects-dashboard (historique vierge,
  e-mail noreply). **Bilingue** : `README.md`/`TUTO.md` (EN) + `README.fr.md`/`TUTO.fr.md` (FR).

## À faire

- Ajouter une capture d'aperçu neutre (`docs/preview.png`) + social preview GitHub.
- Maintenir le miroir public à jour après chaque modif (git archive → push).
