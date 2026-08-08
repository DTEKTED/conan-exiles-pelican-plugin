# Conan Settings Editor (Pelican plugin)

Schema-driven [Pelican Panel](https://github.com/pelican-dev/panel) plugin for **Conan Exiles Enhanced** dedicated servers:

- **Settings** — grouped `ServerSettings.ini` editor with search, PvE/PvP mode presets, stop-to-save safety
- **Mods** — Workshop ID load order, Steam metadata, Workshop pak install jobs (`modlist.txt`)
- **Identity** — server name / join password via egg variables (`SRV_NAME`, `SRV_PW`) plus MOTD, admin password, region

**Version:** see `plugin.json` (currently 0.4.5)

## Install

1. Copy this repository into your panel plugins directory as `conan-settings-editor`:

   ```text
   panel/plugins/conan-settings-editor/
   ```

2. Ensure the panel can load plugins (restart panel if required).
3. Enable **Conan Settings Editor** in the Pelican admin plugin list if it is not auto-enabled.
4. Open a Conan Exiles Enhanced server → **Conan Exiles** nav group:
   - **Conan Settings**
   - **Conan Mods**

### Optional: Workshop download worker

One-click Workshop install requires a **host-side worker** that watches  
`plugins/conan-settings-editor/storage/jobs/` and runs SteamCMD against the server volume.

This repository ships the **panel plugin + job queue API only**. Worker scripts and compose wiring are environment-specific (Docker socket, Wings volume paths). Implement a worker that:

1. Reads `storage/jobs/pending/*.json`
2. Downloads Workshop items for app `440900`
3. Stages `.pak` files into `ConanSandbox/Mods/`
4. Writes atomic `modlist.txt` and updates the job to `done` / `failed`

Until a worker is running, you can still manage load order and upload paks manually.

## Safety

- **Stop the server** before saving settings, load order, or install jobs.
- Backups of INI / modlist are written on change where applicable.
- Do not commit `storage/jobs/` (runtime queue) to git.

## Credits

Settings coverage and mod-list practices were informed by  
[balnaimi/conan-exiles-server](https://github.com/balnaimi/conan-exiles-server) (independent reimplementation).  
UI/safe-edit patterns inspired by  
[palworld-settings-editor](https://github.com/JanPauw/palworld-settings-editor).  

Full text: [`CREDITS.md`](./CREDITS.md).

## License

MIT — see [`LICENSE`](./LICENSE).  
Third-party projects retain their own licenses (see CREDITS).
