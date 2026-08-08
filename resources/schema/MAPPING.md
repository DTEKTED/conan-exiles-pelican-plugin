# Conan settings schema — mapping reference

Generated for plugin `conan-settings-editor` v0.1.0-schema.

## Stats

- **field_count**: 279
- **from_balnaimi**: 240
- **from_live_only**: 39
- **present_on_live**: 218
### by_file

- `ServerSettings.ini`: 268
- `Engine.ini`: 6
- `Game.ini`: 5

### by_group

- `PVP Schedule`: 46
- `Advanced Present Only`: 31
- `Building and Land Claim`: 30
- `Player Survival`: 28
- `Combat and Damage`: 25
- `Harvest and Crafting`: 16
- `Thralls and Followers`: 15
- `Access and Security`: 14
- `Network and Performance`: 13
- `Avatars`: 10
- `Purge`: 9
- `Chat and Social`: 8
- `Death and Loot`: 8
- `Day Night and World`: 7
- `Progression and XP`: 5
- `Rcon`: 5
- `Engine`: 4
- `Combat Mode`: 2
- `Meta`: 2
- `Mods`: 1

### by_type

- `integer`: 145
- `boolean`: 83
- `float`: 37
- `string`: 7
- `password`: 5
- `text`: 2

### by_ini_style

- `int`: 145
- `TrueFalse`: 83
- `float`: 37
- `string`: 14


## Files

- `ServerSettings.ini` → `ConanSandbox/Saved/Config/LinuxServer/ServerSettings.ini`
- `Engine.ini` → `ConanSandbox/Saved/Config/LinuxServer/Engine.ini`
- `Game.ini` → `ConanSandbox/Saved/Config/LinuxServer/Game.ini`
- `modlist.txt` → `ConanSandbox/Mods/modlist.txt`

## Mode presets (INI keys)

### pve — PvE

No player combat; bases protected.

- `PVPEnabled` = `False` (TrueFalse)
- `CombatModeModifier` = `0` (int)
- `CanDamagePlayerOwnedStructures` = `False` (TrueFalse)

### pve-c — PvE-Conflict

Player combat allowed; bases not raidable. Requires PVPEnabled=True and CombatModeModifier=1.

- `PVPEnabled` = `True` (TrueFalse)
- `CombatModeModifier` = `1` (int)
- `CanDamagePlayerOwnedStructures` = `False` (TrueFalse)

### pvp — PvP

Open combat and base raiding.

- `PVPEnabled` = `True` (TrueFalse)
- `CombatModeModifier` = `0` (int)
- `CanDamagePlayerOwnedStructures` = `True` (TrueFalse)

## Groups and field counts

- **Access and Security**: 14 fields
- **Network and Performance**: 13 fields
- **Combat Mode**: 2 fields
- **Combat and Damage**: 25 fields
- **Player Survival**: 28 fields
- **Progression and XP**: 5 fields
- **Harvest and Crafting**: 16 fields
- **Building and Land Claim**: 30 fields
- **Thralls and Followers**: 15 fields
- **Day Night and World**: 7 fields
- **Death and Loot**: 8 fields
- **Chat and Social**: 8 fields
- **Purge**: 9 fields
- **Avatars**: 10 fields
- **PVP Schedule**: 46 fields
- **Mods**: 1 fields
- **Advanced Present Only**: 31 fields
- **Engine**: 4 fields
- **Rcon**: 5 fields
- **Meta**: 2 fields

## Coverage notes

- All **218** keys on the live `ServerSettings.ini` are in the schema (`unknown_keys=0` on validate).
- **50** balnaimi-mapped ServerSettings keys are not present on this live file yet (game may create on demand); still in schema for future edits.
- Engine.ini / Game.ini fields are mapped for completeness; this volume currently only has ServerSettings.ini.
- `env_var` is lineage from balnaimi requirements map; the plugin reads/writes **INI keys**, not env files.

## Credits

See `CREDITS.md`.