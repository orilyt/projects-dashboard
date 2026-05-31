# Project progress dashboard

**English** · [Français](README.fr.md)

> **Open source — [MIT](LICENSE) licensed.** Plain PHP, zero dependencies, a single page.

A **plain-PHP, zero-dependency** dashboard that scans a folder of local web projects and
shows, for each one, its activity (git + last change) and a "real" progress read from an
optional `STATUS.md` at each project's root.

Built for a local dev machine (Laragon, XAMPP, MAMP, `php -S`…) where you juggle many
sites and want a single overview: *what's where, what's moving, what's next*.

<!-- Add your screenshot here, e.g.: ![preview](docs/preview.png) -->

---

## Features

- **Automatic scan** of a folder: each subfolder = a project.
- **Inferred activity**, no config needed: git branch, commit count, uncommitted files,
  last activity (commit or file mtime).
- **"Real" progress** read from a per-project `STATUS.md` (status, %, next step); falls
  back to a presumed status based on recent activity.
- **Multi-track projects**: an aggregated `## Chantiers` (work-streams) table — average
  of the %s, derived status.
- **Table view**: Project · Status · Progress · Activity · Actions columns, sticky header,
  client-side filter/sort.
- **External roots**: show (read-only) folders outside the web root, without ever exposing
  them over HTTP.
- **Optional "Claude" button** (Windows + WSL out of the box; adaptable to Linux/macOS):
  reopen a project in a local terminal. **Off by default** — see [Security](#security).

---

## Requirements

- **PHP 8.0+** (tested on 8.3). No special extension, no Composer dependency.
- A **local web server** serving the folder, your pick:
  - **Laragon** / **XAMPP** / **WAMP** / **MAMP** (Apache + PHP),
  - or PHP's built-in server: `php -S localhost:8000` from the folder.
- The **core (scan + display) is cross-platform** (Windows / Linux / macOS). The
  **"Claude" button** ships ready for **Windows + WSL**, and is adaptable to Linux/macOS
  (see the dedicated section — untested by the author).

---

## Quick start

1. **Copy** this folder into your web root, next to your projects:

   ```
   .../www/
   ├── projets/        ← this dashboard
   ├── my-site-a/
   ├── my-site-b/
   └── ...
   ```

2. **Create your config** from the template:

   ```bash
   cd projets
   cp config.example.php config.php
   ```

3. **Open it** in the browser: `http://localhost/projets/` (or your vhost, e.g.
   `http://projets.test`, or `php -S localhost:8000` then `http://localhost:8000`).

That's it. By default it scans the **parent** folder and lists your projects.

---

## Configuration

Everything is set in **`config.php`** (copied from `config.example.php`, git-ignored so
each machine has its own). Keys:

| key | purpose |
|---|---|
| `root` | Scanned folder (each subfolder = a project). Default: the parent folder. |
| `extra_roots` | **External** folders to also show (read server-side, never served over HTTP). `[]` = none. |
| `exclude` | Subfolders of `root` to ignore. |
| `exclude_prefix` | Any folder starting with this prefix is ignored (`_` by default). |
| `enable_launch` | Enables the "Claude" button (**local exec — see Security**). `false` by default. |
| `launch.wsl_distro` | WSL distro name (`wsl -l -q`). |
| `launch.command` | Command run inside the project folder. |
| `scan_file_cap` | Per-project cap on files scanned to estimate activity (perf). |
| `skip_dirs` | Folders never traversed during that scan (heavy ones). |

---

## The `STATUS.md` convention

For a project to show real progress, put a `STATUS.md` at **its** root (not in the
dashboard). Without it, the dashboard falls back to git/mtime activity. Full template in
[`STATUS.md.example`](STATUS.md.example). Format:

```markdown
---
status: en cours        # idée | en cours | en pause | bloqué | terminé | abandonné
progress: 60            # 0 to 100
next: <concrete next step>
updated: 2026-05-31
---

# Free-form notes (ignored by the dashboard)
```

> Note: the status labels and the `## Chantiers` heading are **French by default** — the
> parser keys on them. The labels (`idée`, `en cours`, `en pause`, `bloqué`, `terminé`,
> `abandonné`) map to colours; any other label still renders, just with a neutral colour.
> The work-streams table must keep the literal `## Chantiers` heading to be detected.

Project on several fronts? Leave `status`/`progress` empty and add a `## Chantiers` table
(the dashboard aggregates the average `progress` + a derived status):

```markdown
## Chantiers

| chantier     | statut   | progress | next                  |
|--------------|----------|----------|-----------------------|
| Public front | en cours | 80       | Finish pricing page   |
| API          | en pause | 40       | Resume after front    |
```

---

## The "Claude" button (optional — Windows + WSL out of the box)

On each row, a button can **reopen the project in a local terminal** (default:
`claude --continue || claude` in the project folder — set `launch.command` to anything:
`code .`, `git status`, your editor…).

It's **off by default**. To enable it (local machine only):

1. `enable_launch => true` in `config.php`.
2. Set `launch.wsl_distro` (`wsl -l -q`) and `launch.command`.
3. Reload. Read the [Security](#security) section first.

### On Linux / macOS — *doable, untested by the author*

The **core** runs everywhere. Only this button ships ready for **Windows + WSL** (it
converts the `C:\…` path to `/mnt/…` and goes through `launch-claude.bat`). The idea —
run a command in a terminal after a `cd` into the project folder — is portable, but
requires **editing the `launch` handler in `index.php`** (drop the Windows path
conversion: on Unix the path is already native) and replacing the `.bat` call with:

- **Linux** (X11/Wayland) — a terminal emulator, e.g.:
  `gnome-terminal --working-directory="$DIR" -- bash -lic "$CMD"`
  (or `konsole --workdir`, `xterm -e`, `x-terminal-emulator`).
- **macOS** — via AppleScript, e.g.:
  `osascript -e 'tell app "Terminal" to do script "cd \"$DIR\" && $CMD"'` (or iTerm).

⚠️ **Shared constraint** (the equivalent of the Windows *window station*): to open a
window, **PHP must run in the user's graphical session** — i.e. launched via
`php -S localhost:8000` from *your* terminal, **not** under a system Apache/nginx (a
daemon has no display access: `DISPLAY`/X on Linux, TCC on macOS).

*These Linux/macOS paths are untested by the author — validate on your machine.*

---

## Security

⚠️ **The "Claude" button runs a system command** (`exec()` server-side). When
`enable_launch = true`, the dashboard exposes an endpoint that spawns a process on the
machine serving PHP. Protections in place:

- **Off by default** (`enable_launch = false`): a fresh deployment runs nothing.
- **POST + per-session CSRF token**: a third-party site can't trigger the action.
- **Loopback only**: the endpoint rejects any request whose IP isn't `127.0.0.1`/`::1`.
- **Allowlist**: only an actually-scanned project name is accepted; the path is derived
  server-side, never built from user input.

**If you enable it:**

- **Single-user local machine only.** **Never** serve this dashboard on `0.0.0.0`, a
  network, a shared or public host with `enable_launch = true` — that would be **remote
  command execution**.
- Don't remove the loopback / CSRF checks.
- `extra_roots` is read server-side but **not** served over HTTP — don't place the
  dashboard *inside* a folder containing secrets and assume they're protected; what's
  reachable is decided by the server's docroot.

---

## Adapt / extend

- **Change what's scanned** → `root`, `exclude`, `exclude_prefix`, `extra_roots`.
- **Change what the button does** → `launch.command` (any command).
- **Change the look** → all CSS is inline in `index.php` (colour variables in `:root`).
  One page, no build.
- **Add a column / filter** → collection is in the `foreach ($projects…)` loop, rendering
  right below, filter/sort in the final `<script>`.

---

## Structure

```
projets/
├── index.php            # the whole app: scan + render + launch endpoint (PHP + HTML + CSS + JS)
├── config.example.php   # config template (committed)
├── config.php           # your local config (git-ignored)
├── launch-claude.bat    # Windows/WSL launcher for the "Claude" button (optional)
├── STATUS.md.example    # STATUS.md template for your projects
├── README.md            # this file (English)
├── README.fr.md         # French version
└── TUTO.md              # step-by-step tutorial (install & adapt) — currently French
```

## License

**Open source under the [MIT](LICENSE) license** © 2026 Jean-Benoît Kauffmann (orilyt.com).
Free to use, modify and redistribute (including commercially); keep the copyright notice.
No third-party dependencies.
