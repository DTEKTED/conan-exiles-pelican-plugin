# Optional Windows host worker for conan-settings-editor job queue.
# Primary target remains Linux (mod-install-worker.sh). This script is best-effort
# for Windows Wings / native SteamCMD operators.
#
# Expected env:
#   CONAN_PLUGIN_ROOT  - path to plugins/conan-settings-editor
#   CONAN_JOBS_ROOT    - defaults to $CONAN_PLUGIN_ROOT/storage/jobs
#   CONAN_VOLUMES_ROOT - Wings volumes root (uuid subdirs)
#   CONAN_STEAMCMD     - path to steamcmd.exe
#
# Job JSON fields (from panel v0.6+):
#   load_order_ids / workshop_ids  - full load order
#   download_ids                   - subset to download
#   config_platform                - LinuxServer | WindowsServer
#   os_hint                        - linux | windows
#
# Semantics: MERGE into existing modlist.txt + .pelican-mod-manifest.json
# (never replace the full list with only download_ids). Prefer implementing
# the same merge rules as install-conan-workshop-mods.sh.

param(
  [int]$PollSeconds = 3
)

$ErrorActionPreference = "Stop"
$PluginRoot = if ($env:CONAN_PLUGIN_ROOT) { $env:CONAN_PLUGIN_ROOT } else { throw "Set CONAN_PLUGIN_ROOT" }
$JobsRoot = if ($env:CONAN_JOBS_ROOT) { $env:CONAN_JOBS_ROOT } else { Join-Path $PluginRoot "storage/jobs" }
$VolumesRoot = if ($env:CONAN_VOLUMES_ROOT) { $env:CONAN_VOLUMES_ROOT } else { throw "Set CONAN_VOLUMES_ROOT" }
$SteamCmd = if ($env:CONAN_STEAMCMD) { $env:CONAN_STEAMCMD } else { "steamcmd.exe" }

foreach ($d in @("pending","running","done","failed","logs")) {
  New-Item -ItemType Directory -Force -Path (Join-Path $JobsRoot $d) | Out-Null
}

Write-Host "[$(Get-Date -Format o)] mod-install-worker.ps1 watching $JobsRoot/pending (Windows best-effort)"

# Skeleton: claim oldest pending, invoke your install implementation, move to done/failed.
# Full port of install-conan-workshop-mods.sh merge logic should live in a companion
# install-conan-workshop-mods.ps1 when a Windows node is available for testing.

while ($true) {
  Get-Date -Format o | Set-Content -Path (Join-Path $JobsRoot "worker.heartbeat")
  $pending = Get-ChildItem -Path (Join-Path $JobsRoot "pending") -Filter "*.json" -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -notlike "*.progress.json" } |
    Sort-Object LastWriteTime
  if ($pending) {
    Write-Host "Pending jobs: $($pending.Count). Implement claim/install in install-conan-workshop-mods.ps1 (not fully ported yet)."
  }
  Start-Sleep -Seconds $PollSeconds
}
