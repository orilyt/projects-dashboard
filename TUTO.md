# Tutorial — install the dashboard and adapt it to your projects

**English** · [Français](TUTO.fr.md)

Goal: go from a blank local server to a dashboard listing **your** projects in ~10 minutes.
Nothing required beyond PHP and a local server.

> For the full option reference, see [README.md](README.md). Here, we just walk through it.

---

## Step 0 — Have a local server

Pick **one** option:

| Tool | How to serve the folder |
|---|---|
| **Laragon** (Windows) | Put the folder in `C:\laragon\www\`. Auto-vhost `http://projets.test`. |
| **XAMPP / WAMP / MAMP** | Put the folder in `htdocs/` (or `www/`). `http://localhost/projets/`. |
| **PHP only** (any OS) | From the folder: `php -S localhost:8000` → `http://localhost:8000`. |

Check PHP is **8.0+**: `php -v`.

---

## Step 1 — Get the files

Clone or copy this `projets/` folder **into your web root, next to your sites**:

```
www/                 (or htdocs/, or whatever folder you serve)
├── projets/         ← the dashboard
├── client-alpha/    ← your projects…
├── shop-beta/
└── api-gamma/
```

The logic: the dashboard scans its **parent folder** and treats each subfolder as a
project. Placed in `www/`, it lists all of `www/`.

---

## Step 2 — Create your configuration

```bash
cd projets
cp config.example.php config.php      # Windows: copy config.example.php config.php
```

`config.php` is **yours** (git-ignored). Open it: the defaults work for "scan the parent
folder". We tune it in step 4.

---

## Step 3 — Open the dashboard

- Laragon / vhost: `http://projets.test`
- XAMPP & co: `http://localhost/projets/`
- `php -S`: `http://localhost:8000`

You should see the list of your folders, with their git activity and a presumed status.
If the page is blank: see [Troubleshooting](#troubleshooting).

---

## Step 4 — Adapt the scan to your layout

In `config.php`:

- **Scan a folder other than the parent**:
  ```php
  'root' => 'C:\\sites',        // Windows
  'root' => '/var/www',         // Linux/macOS
  ```
- **Hide folders** that aren't projects:
  ```php
  'exclude'        => ['projets', 'vendor', '_archives', 'phpmyadmin'],
  'exclude_prefix' => '_',      // also hides _backups, _drafts, …
  ```
- **Show a folder outside the web root** (read server-side, never exposed over HTTP):
  ```php
  'extra_roots' => ['C:\\Backups\\ops'],
  ```

Reload the page after each change.

---

## Step 5 — Give a project a "real" progress

The dashboard guesses activity, but **you** alone know where a project stands. Add a
`STATUS.md` at the **project's** root (not the dashboard's):

```markdown
---
status: en cours
progress: 45
next: Wire up Stripe payment
updated: 2026-05-31
---
```

Reload: the project's row now shows your status, the bar at 45%, and the next step. Copy
[`STATUS.md.example`](STATUS.md.example) as a starting point. (Status labels are French by
default and map to colours — see the README note.)

Project on several fronts? Leave `status`/`progress` empty and add a `## Chantiers` table
(see README) — the dashboard averages them.

---

## Step 6 (optional, advanced) — The "Claude" button / local command

> ⚠️ **Read the README's Security section first.** This enables an endpoint that **runs a
> system command**. For a **single-user local machine** served on `127.0.0.1` only. Never
> on a network / shared host. Windows + WSL.

1. In `config.php`:
   ```php
   'enable_launch' => true,
   'launch' => [
       'wsl_distro' => 'Ubuntu',                  // your distro: wsl -l -q
       'command'    => 'claude --continue || claude', // or 'code .', 'git status', …
   ],
   ```
2. Reload. A button appears on each row; a click opens Windows Terminal in the project
   folder and runs your command.
3. The command is free: replace it with whatever you want to run when opening a project
   (editor, shell, setup script…).

**Linux / macOS**: the shipped mechanism relies on `wt.exe` / `wsl.exe` /
`launch-claude.bat` (Windows). It's **adaptable** (native terminal via
`gnome-terminal`/`osascript`, by editing the `launch` handler in `index.php`) — see the
README, "On Linux / macOS" section. **Untested by the author.** In the meantime leave
`enable_launch => false`; the rest of the dashboard works everywhere.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| **Blank page** | PHP < 8.0, or `config.php` badly copied. Check `php -v`; look at PHP logs / enable `display_errors`. |
| **No project listed** | `root` points to the wrong place, or everything is in `exclude`. |
| **A folder is missing** | It starts with `exclude_prefix` (`_`) or is in `exclude`. |
| **Claude button: `forbidden`** | Normal off-loopback or without a reload (CSRF token). Serve on `127.0.0.1` and reload. |
| **Claude button: `launch désactivé`** | `enable_launch` is `false` in `config.php`. |
| **Claude button: no window** | The server can't open a GUI window (non-interactive session). See the console fallback in `launch-claude.bat`. |

---

## Understand the code in 30 seconds (to extend it)

`index.php` is **a single file** in this order:

1. **Config**: loads `config.php`.
2. **Launch endpoint**: short-circuits on a POST `launch` request (otherwise ignored).
3. **Helpers**: `scanActivity()`, `gitInfo()`, `statusFile()`, etc.
4. **Collection**: a `foreach` over the folders → `$projects` array.
5. **HTML render** + **inline CSS** (`:root` for colours) + **JS** (filter/sort).

To add a column: add the data in the collection, a cell in the render, and a header in
`.thead` if needed. No framework, no build — edit, reload.

---

## License

This project is **open source under the [MIT](LICENSE) license**: use it, modify it and
redistribute it freely (including commercially), keeping the copyright notice. Adapt it to
your needs without restriction.
