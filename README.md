# Conan Settings Editor

A [Pelican Panel](https://github.com/pelican-dev/panel) plugin for **Conan Exiles Enhanced** dedicated servers.

It gives operators a browser UI to:

- Edit **ServerSettings.ini** (grouped form, search, mode helpers)
- Manage **Workshop mod load order** and on-disk `.pak` files
- Queue **SteamCMD Workshop downloads** (with a host-side worker)
- Set basic **server identity** (name / join password via egg vars, MOTD, admin password)

| | |
| --- | --- |
| **Plugin id** | `conan-settings-editor` |
| **Version** | See [`plugin.json`](./plugin.json) |
| **License** | [MIT](./LICENSE) |
| **Repo** | https://github.com/DTEKTED/conan-exiles-pelican-plugin |

---

## Who this is for

- You already run **Pelican Panel + Wings** with a **Conan Exiles Enhanced** egg/server on **Linux or Windows**.
- You want settings and mods in the panel instead of hand-editing INI / SFTP-only workflows.
- Optional: you can run a small **host worker** so Workshop downloads happen on the server (SteamCMD), without desktop managers.

This plugin does **not** replace Wings, the game egg, SteamCMD install of the base game, or your tunnel/DNS setup (playit, Cloudflare, etc.).

---

## Requirements

| Requirement | Notes |
| --- | --- |
| Pelican Panel | Recent Pelican with Filament server pages / plugins enabled |
| Wings node | Server volume reachable for file R/W |
| Conan Enhanced egg | Plugin detects Conan servers only |
| Permissions | Users need file **read** to open pages; **write** to save; power control to stop/start |
| Optional worker | Linux: Docker/host SteamCMD · Windows: native SteamCMD · volumes for app `440900` |

---

## Platform support (Linux and Windows)

**Both Linux and Windows dedicated servers are supported** in a single plugin (not separate editions).

| Platform | Config path | Status |
| --- | --- | --- |
| **Linux Dedicated** | `ConanSandbox/Saved/Config/LinuxServer/` | Supported |
| **Windows Dedicated** | `ConanSandbox/Saved/Config/WindowsServer/` | Supported (v0.6+) |

### How platform is chosen

1. **Auto (default):** look for an existing `ServerSettings.ini` under `LinuxServer` or `WindowsServer`, then egg/image hints.
2. **Override** in plugin config:

```php
// config/conan-settings-editor.php (or panel config merge)
'config_platform' => 'auto', // or 'LinuxServer' | 'WindowsServer'
```

The active platform is shown on **Conan Settings** and **Conan Mods** (“Config platform”).

### Workshop worker by host OS

| Host running the worker | Script / approach |
| --- | --- |
| **Linux** | `mod-install-worker.sh` + `install-conan-workshop-mods.sh` (Docker SteamCMD) — production path on typical Linux Wings |
| **Windows** | `scripts/mod-install-worker.ps1` skeleton + native `steamcmd.exe` — best-effort; complete the install/merge port for your paths |

Panel job JSON includes `config_platform` and `os_hint` so workers can branch. Load-order **merge** rules are the same on both OS targets.

### Docs

| Doc | Contents |
| --- | --- |
| This README | Install, daily ops, safety, AI disclosure |
| [`CREDITS.md`](./CREDITS.md) | Third-party credits |
| Lab / operator notes (if you maintain them separately) | Runbook details: `CONAN_EXILES.md`, `CONAN_MODS_OPTIONS.md` § R3 (Windows roadmap phases) |

---

## Manual Installation Guide

### 1. Copy the plugin into the panel

Clone or copy this repository into the panel plugins directory. The folder name **must** be `conan-settings-editor`:

```bash
# Example: panel data plugins path (adjust to your install)
cd /path/to/panel/plugins
git clone https://github.com/DTEKTED/conan-exiles-pelican-plugin.git conan-settings-editor
```

Expected layout:

```text
panel/plugins/conan-settings-editor/
  plugin.json
  src/
  resources/
  config/
  storage/          # runtime job queue (do not commit secrets/jobs)
  README.md
  CREDITS.md
  LICENSE
```

### 2. Load / enable the plugin

1. Restart or reload the panel container/process if plugins are only discovered on boot.
2. In **Admin → Plugins**, ensure **Conan Settings Editor** is **enabled**.
3. Open a Conan Exiles Enhanced server in the panel.

You should see a nav group **Conan Exiles** with:

- **Conan Settings**
- **Conan Mods**

If the pages do not appear: confirm the egg/server is detected as Conan Enhanced, and that your user has file-read permission on that server.

### 3. First use — settings

1. **Stop the game server** (required to save).
2. Open **Conan Settings**.
3. Change the fields you care about (identity, rates, PvE/PvP helpers, advanced keys).
4. Click **Save**. The plugin writes `ServerSettings.ini` and keeps a timestamped backup when possible.
5. Start the server and verify in-game / logs.

### 4. First use — mods (load order)

1. **Stop the game server**.
2. Open **Conan Mods**.
3. Add Workshop IDs or full Workshop URLs.
   - While the server is stopped, **Add & save** writes the load order immediately.
4. Prefer `ServerModList=modlist.txt` once paks exist (the plugin/worker set this when installing).
5. Start the server so the engine can extract/mount platform content (`LinuxServer` or `WindowsServer`).

**Load order = top first.** Clients should use the same mods in the same order for multiplayer.

### 5. Workshop download worker (optional but recommended)

The panel plugin only **queues** jobs under:

```text
plugins/conan-settings-editor/storage/jobs/
  pending/
  running/
  done/
  failed/
  logs/
```

A host-side worker must:

1. Watch `pending/*.json` (**oldest first**).
2. Download Workshop items for Steam app **`440900`**.
3. Stage `.pak` files into `ConanSandbox/Mods/` on the server volume.
4. **Merge** into existing `modlist.txt` + `.pelican-mod-manifest.json` (do **not** replace the full list with only the IDs from one job).
5. Move the job to `done/` or `failed/` and write progress/logs.

Jobs include:

| Field | Meaning |
| --- | --- |
| `load_order_ids` / `workshop_ids` | Full desired load order (preserve on disk) |
| `download_ids` | Subset to SteamCMD this run (missing paks) |

This repository focuses on the **panel plugin + job JSON API**. Worker scripts and compose are environment-specific (Docker socket, host bind paths for Wings volumes). Wire a worker that matches your host layout.

Until a worker runs, you can still:

- Edit load order in the UI
- Upload `.pak` files via Pelican file manager / SFTP

### 6. Day-to-day operator checklist

```text
Stop server
  → Edit settings and/or mods in the panel
  → Wait for any Workshop job queue to finish (empty pending/running)
  → Start server
  → Confirm mount status after boot (Mounted / Extracted)
```

- **Do not** download Workshop paks while the game process is running.
- Mount badges like “not mounted” are only meaningful **after** a start; offline they show “mounts after next server start”.
- Removing a mod from the list (while stopped + save) removes it from the load order and deletes that mod’s mapped `.pak` when the ID was previously known.

---

## Safety

- **Stop before save** for settings, load order, and install jobs.
- Backups of INI / modlist are written on change where applicable.
- Do **not** commit `storage/jobs/` runtime files or any credentials.
- RCON and admin passwords: treat as secrets; avoid logging them.

---

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| No Conan nav items | Egg detection, plugin enabled, file-read permission |
| Cannot save | Server must be stopped; need file-write permission |
| Download stuck in `pending` | Worker not running; heartbeat `storage/jobs/worker.heartbeat` |
| Job failed | `storage/jobs/logs/<job-id>.log` and `failed/` JSON |
| Mods missing after download | Worker must **merge** load order, not rewrite from download-only IDs |
| “Not mounted” while offline | Expected older wording; current builds soften this until after start |
| Hostname join fails in Conan | Many clients need **IP:port** Direct Connect, not hostname |

---

## AI usage disclosure

This project has been developed with **AI-assisted tooling** but I reviewed and approved every single line of code.

If you fork or reuse this code, assume you should re-validate behavior against your Pelican/Wings/Conan versions.

---

## Credits

Settings coverage and Workshop mod-list practices were informed by  
[balnaimi/conan-exiles-server](https://github.com/balnaimi/conan-exiles-server) (independent reimplementation; not affiliated).  

UI and safe-edit patterns inspired by  
[palworld-settings-editor](https://github.com/JanPauw/palworld-settings-editor).  

Full text: [`CREDITS.md`](./CREDITS.md).

---

## License

[MIT](./LICENSE) — third-party projects keep their own licenses (see CREDITS).
