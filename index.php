<?php
/**
 * Dashboard d'avancement des projets — scan du filesystem à chaque chargement
 * (PHP pur, zéro dépendance, une seule page). Voir README.md / TUTO.md.
 *
 * Modèle "hybride" :
 *   - Activité déduite automatiquement (git + mtime) pour TOUS les projets.
 *   - Avancement "métier" lu depuis un STATUS.md optionnel à la racine d'un projet
 *     (voir STATUS.md.example). Quand il manque, on retombe sur l'activité.
 *
 * Open source — licence MIT (voir le fichier LICENSE). © 2026 Jean-Benoît Kauffmann (orilyt.com).
 *
 * @license MIT
 */
// SPDX-License-Identifier: MIT

declare(strict_types=1);
date_default_timezone_set('Europe/Paris');

// Jeton CSRF de session (le bouton "▶ Claude" déclenche un exec côté serveur).
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* ------------------------------------------------------------------ */
/* Configuration                                                       */
/* Éditez config.php (copié depuis config.example.php). Sans config.php, */
/* ce sont les valeurs par défaut de config.example.php qui s'appliquent. */
/* ------------------------------------------------------------------ */

$CFG = require (is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/config.example.php');

$ROOT           = $CFG['root'];
$EXTRA_ROOTS    = $CFG['extra_roots'];
$EXCLUDE        = $CFG['exclude'];
$EXCLUDE_PREFIX = $CFG['exclude_prefix'];
define('SCAN_FILE_CAP', $CFG['scan_file_cap']);
define('SKIP_DIRS', $CFG['skip_dirs']);

/* ------------------------------------------------------------------ */
/* Lancement d'une session Claude Code dans un projet (bouton ▶ Claude) */
/* OUTIL LOCAL MONO-POSTE : spawn un terminal sur la machine Laragon.   */
/* Sécurité : le nom reçu doit matcher EXACTEMENT un projet scanné ;    */
/* le chemin est dérivé côté serveur, jamais construit depuis l'input.  */
/* ------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['launch'])) {
    header('Content-Type: application/json');

    // Fonctionnalité opt-in : désactivée par défaut (config). exec() = dangereux.
    if (empty($CFG['enable_launch'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'launch désactivé (enable_launch=false)']);
        exit;
    }

    // Anti-CSRF : POST only (déjà filtré ci-dessus) + token de session + loopback.
    // Le token via header custom impose une requête same-origin (un site tiers ne peut
    // pas le poser sans CORS). Le loopback ferme aussi le cas "Laragon exposé réseau".
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true) || !hash_equals($_SESSION['csrf'], $tok)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'forbidden']);
        exit;
    }

    $req = (string) $_POST['launch'];

    // Liste blanche nom -> chemin (léger, sans scan mtime).
    $allowed = [];
    foreach (scandir($ROOT) as $n) {
        if ($n === '.' || $n === '..') continue;
        if (in_array($n, $EXCLUDE, true)) continue;
        if ($n[0] === $EXCLUDE_PREFIX || $n[0] === '.') continue;
        if (is_dir("$ROOT/$n")) $allowed[$n] = "$ROOT/$n";
    }
    foreach ($EXTRA_ROOTS as $ext) {
        if (is_dir($ext)) $allowed[basename($ext)] = $ext;
    }

    if (!isset($allowed[$req])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'err' => 'projet inconnu']);
        exit;
    }

    // Chemin Windows -> chemin WSL (/mnt/<lettre>/...).
    $win = str_replace('\\', '/', $allowed[$req]);
    if (!preg_match('~^([A-Za-z]):/(.*)$~', $win, $m)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'err' => 'chemin non convertible']);
        exit;
    }
    $wsl = '/mnt/' . strtolower($m[1]) . '/' . $m[2];

    // Le .bat encapsule wt.exe -> wsl -> <command> (quoting maîtrisé). __DIR__ sans espace.
    $bat    = __DIR__ . '\\launch-claude.bat';
    $distro = $CFG['launch']['wsl_distro'];
    $cmd    = $CFG['launch']['command'];
    exec('cmd /c ' . $bat . ' ' . escapeshellarg($wsl)
            . ' ' . escapeshellarg($distro) . ' ' . escapeshellarg($cmd), $out, $rc);

    echo json_encode(['ok' => $rc === 0, 'path' => $wsl, 'rc' => $rc]);
    exit;
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Dernière activité + nb fichiers via scan borné, en ignorant les dossiers lourds. */
function scanActivity(string $dir): array {
    $maxMtime = 0;
    $count = 0;
    $stack = [$dir];
    while ($stack && $count < SCAN_FILE_CAP) {
        $current = array_pop($stack);
        $items = @scandir($current);
        if ($items === false) continue;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $current . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                if (in_array($item, SKIP_DIRS, true) || $item[0] === '.') continue;
                $stack[] = $path;
            } else {
                $count++;
                $m = @filemtime($path);
                if ($m && $m > $maxMtime) $maxMtime = $m;
                if ($count >= SCAN_FILE_CAP) break;
            }
        }
    }
    return ['mtime' => $maxMtime, 'files' => $count, 'capped' => $count >= SCAN_FILE_CAP];
}

/** Lit le dernier commit depuis .git/logs/HEAD sans dépendre du binaire git. */
function gitInfo(string $dir): ?array {
    $gitDir = $dir . '/.git';
    if (!is_dir($gitDir)) return null;

    $branch = null;
    $head = @file_get_contents($gitDir . '/HEAD');
    if ($head && preg_match('~ref:\s*refs/heads/(.+)~', $head, $m)) {
        $branch = trim($m[1]);
    }

    $commitTime = null; $commitMsg = null; $commitCount = null;
    $log = $gitDir . '/logs/HEAD';
    if (is_file($log)) {
        $lines = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            $commitCount = count($lines);
            $last = end($lines);
            // <old> <new> <name> <email> <ts> <tz>\t<message>
            if (preg_match('~> (\d{9,}) [+-]\d{4}\t(.*)$~', $last, $m)) {
                $commitTime = (int)$m[1];
                $commitMsg = preg_replace('~^(commit|commit \(initial\)|commit \(merge\)|commit \(amend\)):\s*~', '', $m[2]);
            }
        }
    }

    // Bonus : modifs non commitées si le binaire git est dispo. Best-effort.
    $dirty = null;
    if (function_exists('exec')) {
        $out = [];
        $win = stripos(PHP_OS, 'WIN') === 0;
        $cd = $win ? 'cd /d ' : 'cd ';
        @exec($cd . escapeshellarg($dir) . ' && git status --porcelain 2>' . ($win ? 'NUL' : '/dev/null'), $out, $code);
        if ($code === 0) $dirty = count($out);
    }

    return [
        'branch' => $branch,
        'commitTime' => $commitTime,
        'commitMsg' => $commitMsg,
        'commitCount' => $commitCount,
        'dirty' => $dirty,
    ];
}

/** Parse le frontmatter d'un STATUS.md (clés: status, progress, next, updated). */
function statusFile(string $dir): ?array {
    foreach (['STATUS.md', 'status.md'] as $name) {
        $path = $dir . '/' . $name;
        if (!is_file($path)) continue;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $data = ['status' => null, 'progress' => null, 'next' => null, 'updated' => null, 'tracks' => []];
        if (preg_match('~^\s*---\s*(.*?)\s*---~s', $raw, $m)) {
            foreach (preg_split('~\r?\n~', $m[1]) as $line) {
                if (preg_match('~^\s*([a-zA-Z_]+)\s*:\s*(.+?)\s*$~', $line, $kv)) {
                    $k = strtolower($kv[1]);
                    if (array_key_exists($k, $data) && $k !== 'tracks') $data[$k] = trim($kv[2]);
                }
            }
        }
        if ($data['progress'] !== null) $data['progress'] = (int)$data['progress'];

        // Tableau « ## Chantiers » dans le corps : | chantier | statut | progress | next |
        $body = preg_replace('~^\s*---\s*.*?\s*---~s', '', $raw, 1);
        if (preg_match('~^[ \t]*#{2,}\s*Chantiers?\b.*$~mi', $body, $mm, PREG_OFFSET_CAPTURE)) {
            $rest = substr($body, $mm[0][1] + strlen($mm[0][0]));
            if (preg_match('~^[ \t]*#{2,}\s~m', $rest, $h, PREG_OFFSET_CAPTURE)) {
                $rest = substr($rest, 0, $h[0][1]);          // s'arrête au titre suivant
            }
            foreach (preg_split('~\r?\n~', $rest) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] !== '|') continue;
                if (preg_match('~^\|[\s:\-\|]+\|?$~', $line)) continue;   // séparateur |---|
                $cells = array_map('trim', explode('|', trim($line, '|')));
                $c0 = strtolower($cells[0] ?? '');
                if ($c0 === '' || in_array($c0, ['chantier', 'chantiers', 'name', 'nom', 'track'], true)) continue;
                $prog = (isset($cells[2]) && $cells[2] !== '' && is_numeric($cells[2])) ? (int)$cells[2] : null;
                $data['tracks'][] = [
                    'name'     => $cells[0],
                    'status'   => $cells[1] ?? '',
                    'progress' => $prog,
                    'next'     => $cells[3] ?? '',
                ];
            }
        }
        return $data;
    }
    return null;
}

/** Statut déduit de l'activité quand aucun STATUS.md n'existe. */
function presumedStatus(int $mtime): array {
    if ($mtime === 0) return ['inconnu', '#6b7280'];
    $days = (time() - $mtime) / 86400;
    if ($days <= 7)  return ['actif', '#22c55e'];
    if ($days <= 30) return ['ralenti', '#eab308'];
    if ($days <= 90) return ['au ralenti', '#f97316'];
    return ['en sommeil', '#6b7280'];
}

function badgeColor(string $status): string {
    return match (strtolower(trim($status))) {
        'terminé', 'termine', 'fini', 'done'        => '#22c55e',
        'en cours', 'actif', 'wip'                  => '#3b82f6',
        'en pause', 'pause'                         => '#eab308',
        'bloqué', 'bloque', 'blocked'              => '#ef4444',
        'idée', 'idee', 'idea', 'brouillon'        => '#a855f7',
        'abandonné', 'abandonne', 'dropped'        => '#6b7280',
        default                                     => '#64748b',
    };
}

/** Statut projet agrégé à partir de ses chantiers. */
function aggregateStatus(array $tracks): array {
    $st = array_map(fn($t) => strtolower(trim((string)$t['status'])), $tracks);
    $isDone = fn($s) => in_array($s, ['terminé','termine','fini','done'], true);
    $isDead = fn($s) => in_array($s, ['abandonné','abandonne','dropped'], true);
    if (array_filter($st, fn($s) => in_array($s, ['bloqué','bloque','blocked'], true)))
        return ['bloqué', badgeColor('bloqué')];
    if ($st && count(array_filter($st, $isDone)) === count($st))
        return ['terminé', badgeColor('terminé')];
    $alive = array_filter($st, fn($s) => $s !== '' && !$isDone($s) && !$isDead($s));
    if (array_filter($st, fn($s) => in_array($s, ['en cours','actif','wip'], true)) || $alive === [])
        return ['en cours', badgeColor('en cours')];
    if (array_filter($st, fn($s) => in_array($s, ['en pause','pause'], true)))
        return ['en pause', badgeColor('en pause')];
    return ['en cours', badgeColor('en cours')];
}

function humanAge(int $ts): string {
    if ($ts === 0) return '—';
    $d = time() - $ts;
    if ($d < 3600)   return floor($d/60) . ' min';
    if ($d < 86400)  return floor($d/3600) . ' h';
    if ($d < 2592000) return floor($d/86400) . ' j';
    if ($d < 31536000) return floor($d/2592000) . ' mois';
    return floor($d/31536000) . ' an' . ($d >= 63072000 ? 's' : '');
}

function bytesHuman(int $n): string {
    $u = ['o','Ko','Mo','Go']; $i = 0;
    while ($n >= 1024 && $i < 3) { $n /= 1024; $i++; }
    return round($n, $n < 10 && $i > 0 ? 1 : 0) . ' ' . $u[$i];
}

/* ------------------------------------------------------------------ */
/* Collecte                                                            */
/* ------------------------------------------------------------------ */

// Candidats = enfants de www (filtrés) + racines externes déclarées.
$candidates = [];
foreach (scandir($ROOT) as $name) {
    if ($name === '.' || $name === '..') continue;
    if (in_array($name, $EXCLUDE, true)) continue;
    if ($name[0] === $EXCLUDE_PREFIX || $name[0] === '.') continue;
    $path = $ROOT . '/' . $name;
    if (!is_dir($path)) continue;
    $candidates[] = ['name' => $name, 'path' => $path, 'external' => false];
}
foreach ($EXTRA_ROOTS as $extPath) {
    if (!is_dir($extPath)) continue;
    $candidates[] = ['name' => basename($extPath), 'path' => $extPath, 'external' => true];
}

$projects = [];
foreach ($candidates as $cand) {
    $name = $cand['name'];
    $path = $cand['path'];

    $act    = scanActivity($path);
    $git    = gitInfo($path);
    $status = statusFile($path);

    $hasClaude = is_dir($path . '/.claude');
    $hasClaudeMd = is_file($path . '/CLAUDE.md');

    // Source d'horodatage : commit git si dispo, sinon mtime fichiers.
    $lastTs = max((int)($git['commitTime'] ?? 0), $act['mtime']);

    $tracks = $status['tracks'] ?? [];

    // Avancement projet : valeur déclarée, sinon moyenne des chantiers chiffrés.
    $progress = $status['progress'] ?? null;
    if ($progress === null && $tracks) {
        $vals = array_filter(array_column($tracks, 'progress'), fn($v) => $v !== null);
        if ($vals) $progress = (int) round(array_sum($vals) / count($vals));
    }

    if ($status && $status['status']) {
        $label = $status['status'];
        $color = badgeColor($status['status']);
        $auto  = false;
    } elseif ($tracks) {
        [$label, $color] = aggregateStatus($tracks);
        $auto = false;
    } else {
        [$label, $color] = presumedStatus($lastTs);
        $auto = true;
    }

    $projects[] = [
        'name'       => $name,
        'lastTs'     => $lastTs,
        'files'      => $act['files'],
        'capped'     => $act['capped'],
        'git'        => $git,
        'status'     => $status,
        'label'      => $label,
        'color'      => $color,
        'autoStatus' => $auto,
        'hasClaude'  => $hasClaude,
        'hasClaudeMd'=> $hasClaudeMd,
        'progress'   => $progress,
        'next'       => $status['next'] ?? null,
        'tracks'     => $tracks,
        'external'   => $cand['external'],
    ];
}

// Tri par défaut : activité la plus récente d'abord.
usort($projects, fn($a, $b) => $b['lastTs'] <=> $a['lastTs']);

// Stats d'en-tête.
$total     = count($projects);
$claudeNb  = count(array_filter($projects, fn($p) => $p['hasClaude'] || $p['hasClaudeMd']));
$activeNb  = count(array_filter($projects, fn($p) => $p['lastTs'] > 0 && (time() - $p['lastTs']) <= 7*86400));
$withStatus= count(array_filter($projects, fn($p) => $p['status'] && $p['status']['status']));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($CSRF, ENT_QUOTES) ?>">
<title>Projets — Laragon</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23121826'/><rect x='6' y='17' width='5' height='9' rx='1.5' fill='%236366f1'/><rect x='13.5' y='11' width='5' height='15' rx='1.5' fill='%2393c5fd'/><rect x='21' y='6' width='5' height='20' rx='1.5' fill='%2322c55e'/></svg>">
<style>
  :root{
    --bg:#0b0f17; --panel:#121826; --panel2:#171f30; --line:#222c40;
    --txt:#e6edf6; --muted:#8b97ab; --accent:#6366f1;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);
    font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    -webkit-font-smoothing:antialiased}
  .wrap{max-width:1200px;margin:0 auto;padding:32px 20px 80px}
  header h1{margin:0 0 4px;font-size:24px;letter-spacing:-.02em}
  header p{margin:0;color:var(--muted);font-size:13px}
  .stats{display:flex;gap:12px;flex-wrap:wrap;margin:24px 0}
  .stat{background:var(--panel);border:1px solid var(--line);border-radius:12px;
    padding:14px 18px;min-width:120px}
  .stat b{display:block;font-size:26px;line-height:1.1}
  .stat span{color:var(--muted);font-size:12px}
  .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:8px 0 20px}
  .toolbar input,.toolbar select{background:var(--panel);border:1px solid var(--line);
    color:var(--txt);border-radius:9px;padding:9px 12px;font-size:13px;outline:none}
  .toolbar input{flex:1;min-width:200px}
  .toolbar input:focus,.toolbar select:focus{border-color:var(--accent)}
  .list{border:1px solid var(--line);border-radius:14px;background:var(--panel)}
  .thead,.row{display:grid;
    grid-template-columns:minmax(170px,1.6fr) 132px 172px 92px minmax(150px,auto);
    align-items:center;gap:6px 16px;padding:11px 18px}
  .thead{position:sticky;top:0;z-index:5;background:var(--panel2);
    border-bottom:1px solid var(--line);border-radius:14px 14px 0 0;
    font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;
    font-weight:700;color:var(--muted)}
  .thead .r{text-align:right}
  .row{border-top:1px solid var(--line);transition:background .12s}
  .thead + .row{border-top:none}
  .row:last-child{border-radius:0 0 14px 14px}
  .row:hover{background:var(--panel2)}
  .c-proj{display:flex;align-items:center;gap:8px;min-width:0}
  .c-proj .name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .c-age{justify-self:end}
  .c-act{display:flex;align-items:center;gap:14px;justify-self:end}
  .pdash{color:var(--muted)}
  .rowsub{grid-column:1/-1;display:flex;flex-direction:column;gap:6px;margin-top:9px}
  .name{font-weight:600;font-size:16px;letter-spacing:-.01em}
  .badge{font-size:13px;font-weight:600;padding:3px 9px;border-radius:999px;
    color:#fff;white-space:nowrap}
  .badge.auto{opacity:.7;font-style:italic}
  .next{font-size:13px;color:#dbe3ee;background:var(--panel2);
    border-left:3px solid var(--accent);padding:3px 9px;border-radius:0 7px 7px 0}
  .meta{display:flex;flex-wrap:wrap;gap:6px;font-size:13px;color:#aab6c9;align-items:center}
  .chip{background:#1b2335;border:1px solid var(--line);border-radius:7px;color:#c6d1e1;
    padding:2px 8px;display:inline-flex;gap:5px;align-items:center}
  .chip.c{color:#c4b5fd}.chip.g{color:#93c5fd}.chip.dirty{color:#fca5a5}
  .commit{color:#aab6c9;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .pwrap{display:flex;align-items:center;gap:8px}
  .pbar{width:100px;height:6px;background:var(--panel2);border-radius:99px;overflow:hidden}
  .pbar i{display:block;height:100%;border-radius:99px}
  .age{min-width:54px;text-align:right;font-size:13px;color:#aab6c9}
  .open{color:#a5b4fc;text-decoration:none;font-weight:600;font-size:13px}
  .open:hover{color:#c7d2fe;text-decoration:underline}
  .claunch{background:var(--accent);border:1px solid #818cf8;color:#fff;
    font:700 13px/1 inherit;padding:4px 10px;border-radius:6px;cursor:pointer;
    white-space:nowrap;box-shadow:0 1px 2px rgba(0,0,0,.3);
    transition:background .12s,border-color .12s,box-shadow .12s}
  .claunch:hover{background:#818cf8;border-color:#a5b4fc;box-shadow:0 2px 6px rgba(99,102,241,.45)}
  .claunch:disabled{cursor:default;background:var(--panel2);color:var(--muted);
    border-color:var(--line);box-shadow:none}
  details.tracks{margin-top:2px}
  details.tracks>summary{cursor:pointer;font-size:13px;color:var(--accent);
    list-style:none;display:inline-flex;align-items:center;gap:5px;user-select:none}
  details.tracks>summary::-webkit-details-marker{display:none}
  details.tracks>summary::before{content:"\25B8";display:inline-block;transition:transform .15s}
  details.tracks[open]>summary::before{transform:rotate(90deg)}
  .track{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;
    padding:7px 0 7px 18px;font-size:13px;border-top:1px solid var(--line);color:#aab6c9}
  .track:first-of-type{margin-top:6px}
  .tname{font-weight:600;color:var(--txt);min-width:110px}
  .tbadge{font-size:10px;font-weight:600;padding:2px 7px;border-radius:999px;color:#fff;white-space:nowrap}
  .tprog{display:inline-flex;align-items:center;gap:7px;white-space:nowrap}
  .tbar{width:70px;height:5px;background:var(--panel2);border-radius:99px;overflow:hidden;display:inline-block}
  .tbar i{display:block;height:100%;border-radius:99px}
  .tnext{flex-basis:100%;color:var(--muted)}
  .empty{color:var(--muted);text-align:center;padding:40px}
  @media(max-width:760px){
    .thead{display:none}
    .row{grid-template-columns:1fr;gap:5px}
    .c-age,.c-act{justify-self:start}
    .rowsub{margin-top:5px}
    .commit{max-width:100%}
  }
  footer{margin-top:40px;color:var(--muted);font-size:12px;text-align:center}
  footer code{background:var(--panel);padding:2px 6px;border-radius:5px;color:#cbd5e1}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>Tableau de bord des projets</h1>
    <p>Scan de <code style="color:#cbd5e1">c:\laragon\www</code><?php if ($EXTRA_ROOTS): ?> + <?= count($EXTRA_ROOTS) ?> source<?= count($EXTRA_ROOTS)>1?'s':'' ?> externe<?= count($EXTRA_ROOTS)>1?'s':'' ?> (lecture seule)<?php endif ?> · <?= date('d/m/Y H:i') ?></p>
  </header>

  <div class="stats">
    <div class="stat"><b><?= $total ?></b><span>projets</span></div>
    <div class="stat"><b><?= $activeNb ?></b><span>actifs (7 j)</span></div>
    <div class="stat"><b><?= $claudeNb ?></b><span>outillés Claude</span></div>
    <div class="stat"><b><?= $withStatus ?></b><span>avec STATUS.md</span></div>
  </div>

  <div class="toolbar">
    <input id="q" type="search" placeholder="Filtrer par nom…" autocomplete="off">
    <select id="sort">
      <option value="recent">Activité récente</option>
      <option value="name">Nom (A→Z)</option>
      <option value="progress">Avancement</option>
    </select>
    <select id="filter">
      <option value="all">Tous</option>
      <option value="claude">Claude uniquement</option>
      <option value="status">Avec STATUS.md</option>
      <option value="git">Avec git</option>
    </select>
  </div>

  <div class="list" id="list">
  <div class="thead">
    <div>Projet</div><div>Statut</div><div>Avancement</div>
    <div class="r">Activité</div><div class="r">Actions</div>
  </div>
  <?php foreach ($projects as $p):
      $url = 'http://' . strtolower($p['name']) . '.test';
      $g = $p['git'];
  ?>
    <div class="row"
        data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
        data-claude="<?= $p['hasClaude'] || $p['hasClaudeMd'] ? 1 : 0 ?>"
        data-status="<?= $p['status'] && $p['status']['status'] ? 1 : 0 ?>"
        data-git="<?= $g ? 1 : 0 ?>"
        data-ts="<?= $p['lastTs'] ?>"
        data-progress="<?= $p['progress'] ?? -1 ?>">
      <div class="c-proj">
        <span class="name"><?= htmlspecialchars($p['name']) ?></span>
        <?php if ($p['external']): ?><span class="chip" style="background:#3b2f1a;color:#fbbf24" title="Dossier hors c:\laragon\www, lu en lecture seule — non servi en HTTP">externe</span><?php endif ?>
      </div>
      <div class="c-status">
        <span class="badge <?= $p['autoStatus'] ? 'auto' : '' ?>"
              style="background:<?= $p['color'] ?>"><?= htmlspecialchars($p['label']) ?></span>
      </div>
      <div class="c-prog">
        <?php if ($p['progress'] !== null):
            $aggProg = !($p['status'] && $p['status']['progress'] !== null) && !empty($p['tracks']); ?>
          <span class="pwrap" title="<?= $aggProg ? 'moyenne des chantiers' : 'avancement déclaré' ?>">
            <span class="pbar"><i style="width:<?= max(0,min(100,$p['progress'])) ?>%;background:<?= $p['color'] ?>"></i></span>
            <b style="color:var(--txt)"><?= (int)$p['progress'] ?><?= $aggProg ? '<small style="color:var(--muted);font-weight:400">~</small>' : '' ?>%</b>
          </span>
        <?php else: ?><span class="pdash">—</span><?php endif ?>
      </div>
      <div class="c-age"><span class="age" title="dernière activité">⏱ <?= humanAge($p['lastTs']) ?></span></div>
      <div class="c-act">
        <?php if ($CFG['enable_launch']): ?>
        <button class="claunch" data-proj="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
                title="Ouvrir / continuer ce projet dans Claude Code (terminal local)">▶ Claude</button>
        <?php endif ?>
        <?php if (!$p['external']): ?>
          <a class="open" href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener">ouvrir ↗</a>
        <?php elseif ($CFG['enable_launch']): ?>
          <span class="open" aria-hidden="true" style="visibility:hidden">ouvrir ↗</span>
        <?php endif ?>
      </div>
      <div class="rowsub">
        <?php if ($p['next']): ?><div><span class="next">→ <?= htmlspecialchars($p['next']) ?></span></div><?php endif ?>
        <div class="meta">
          <?php if ($p['hasClaudeMd']): ?><span class="chip c">CLAUDE.md</span><?php endif ?>
          <?php if ($p['hasClaude']): ?><span class="chip c">.claude</span><?php endif ?>
          <?php if ($g): ?>
            <span class="chip g">⎇ <?= htmlspecialchars($g['branch'] ?? '?') ?><?= $g['commitCount'] ? ' · '.$g['commitCount'].' commits' : '' ?></span>
            <?php if ($g['dirty'] !== null && $g['dirty'] > 0): ?>
              <span class="chip dirty">● <?= $g['dirty'] ?> non commité<?= $g['dirty']>1?'s':'' ?></span>
            <?php endif ?>
          <?php endif ?>
          <span class="chip"><?= $p['files'] ?><?= $p['capped'] ? '+' : '' ?> fichiers</span>
          <?php if ($g && $g['commitMsg']): ?>
            <span class="commit" title="<?= htmlspecialchars($g['commitMsg']) ?>">⤷ <?= htmlspecialchars($g['commitMsg']) ?></span>
          <?php endif ?>
        </div>
        <?php if (!empty($p['tracks'])): ?>
        <details class="tracks">
          <summary><?= count($p['tracks']) ?> chantier<?= count($p['tracks']) > 1 ? 's' : '' ?></summary>
          <?php foreach ($p['tracks'] as $t): $tc = badgeColor((string)$t['status']); ?>
            <div class="track">
              <span class="tname"><?= htmlspecialchars($t['name']) ?></span>
              <?php if (trim((string)$t['status']) !== ''): ?>
                <span class="tbadge" style="background:<?= $tc ?>"><?= htmlspecialchars($t['status']) ?></span>
              <?php endif ?>
              <?php if ($t['progress'] !== null): ?>
                <span class="tprog"><span class="tbar"><i style="width:<?= max(0,min(100,$t['progress'])) ?>%;background:<?= $tc ?>"></i></span><?= (int)$t['progress'] ?>%</span>
              <?php endif ?>
              <?php if (trim((string)$t['next']) !== ''): ?>
                <span class="tnext">→ <?= htmlspecialchars($t['next']) ?></span>
              <?php endif ?>
            </div>
          <?php endforeach ?>
        </details>
        <?php endif ?>
      </div>
    </div>
  <?php endforeach ?>
  </div>
  <div class="empty" id="empty" style="display:none">Aucun projet ne correspond.</div>

  <footer>
    Avancement réel = présence d'un <code>STATUS.md</code> à la racine du projet
    (statut en italique = déduit de l'activité, pas déclaré).
    Voir <code>STATUS.md.example</code>.
  </footer>
</div>

<script>
(() => {
  const grid = document.getElementById('list');
  const cards = [...grid.querySelectorAll('.row')];
  const q = document.getElementById('q');
  const sortSel = document.getElementById('sort');
  const filterSel = document.getElementById('filter');

  function apply() {
    const term = q.value.trim().toLowerCase();
    const f = filterSel.value;
    let visible = 0;
    cards.forEach(c => {
      let ok = !term || c.dataset.name.includes(term);
      if (ok && f === 'claude')  ok = c.dataset.claude === '1';
      if (ok && f === 'status')  ok = c.dataset.status === '1';
      if (ok && f === 'git')     ok = c.dataset.git === '1';
      c.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });
    document.getElementById('empty').style.display = visible ? 'none' : 'block';

    const s = sortSel.value;
    const sorted = [...cards].sort((a, b) => {
      if (s === 'name')     return a.dataset.name.localeCompare(b.dataset.name);
      if (s === 'progress') return (+b.dataset.progress) - (+a.dataset.progress);
      return (+b.dataset.ts) - (+a.dataset.ts);
    });
    sorted.forEach(c => grid.appendChild(c));
  }
  q.addEventListener('input', apply);
  sortSel.addEventListener('change', apply);
  filterSel.addEventListener('change', apply);

  // Bouton "▶ Claude" : POST same-origin avec token CSRF -> spawn terminal local.
  const CSRF = document.querySelector('meta[name=csrf]').content;
  document.querySelectorAll('.claunch').forEach(b => {
    b.addEventListener('click', async () => {
      const proj = b.dataset.proj, label = b.textContent;
      b.disabled = true; b.textContent = '… lancement';
      try {
        const r = await fetch('index.php', {
          method: 'POST',
          headers: {
            'X-CSRF-Token': CSRF,
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: 'launch=' + encodeURIComponent(proj)
        });
        const j = await r.json();
        b.textContent = j.ok ? '✓ lancé' : '✗ ' + (j.err || 'échec');
      } catch (e) { b.textContent = '✗ échec'; }
      setTimeout(() => { b.textContent = label; b.disabled = false; }, 2500);
    });
  });
})();
</script>
</body>
</html>
