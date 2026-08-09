<?php

namespace Dtektion\ConanSettingsEditor\Services;

/**
 * Load-order model for Conan mods.
 *
 * Source of truth for Workshop ID order on this install style:
 *   ServerSettings.ini → ServerModList=id1,id2,id3
 *
 * Optional companion file after downloads (M2):
 *   ConanSandbox/Mods/modlist.txt with lines like *Package.pak
 */
class ConanModListService
{
    public const MODS_DIR = 'ConanSandbox/Mods';

    public const MODLIST_PATH = 'ConanSandbox/Mods/modlist.txt';

    public const MANIFEST_PATH = 'ConanSandbox/Mods/.pelican-mod-manifest.json';

    public const EXTRACT_DIR = 'ConanSandbox/Saved/ExtractedMods';

    public const LOG_PATH = 'ConanSandbox/Saved/Logs/ConanSandbox.log';

    public function __construct(
        private readonly ConanSettingsFileService $files,
        private readonly ConanSettingsSchema $schema,
        private readonly ConanIniMapper $mapper,
    ) {}

    /**
     * @return array{
     *   workshop_ids: list<string>,
     *   server_mod_list_raw: string,
     *   server_mod_list_mode: 'ids'|'filename'|'empty'|'unknown',
     *   modlist_path: string,
     *   modlist_entries: list<array{line: string, enabled: bool, pak_name: ?string}>,
     *   paks_on_disk: list<string>,
     *   settings_path: string
     * }
     */
    public function inspect(mixed $server): array
    {
        $settingsPath = $this->files->resolveExistingPath(
            $server,
            $this->schema->pathFallbacksForServer($server, 'ServerSettings.ini')
        ) ?? $this->schema->pathForServer($server, 'ServerSettings.ini');

        $rawList = '';
        $mode = 'empty';
        $ids = [];

        if ($this->files->exists($server, $settingsPath)) {
            $ini = $this->files->read($server, $settingsPath);
            $parsed = $this->mapper->parse($ini, 'ServerSettings.ini');
            $rawList = (string) ($parsed['sections']['ServerSettings']['ServerModList']
                ?? $parsed['typed']['ServerModList']
                ?? '');
            // typed may have transformed; prefer raw section map
            foreach ($parsed['sections'] as $section => $pairs) {
                if (array_key_exists('ServerModList', $pairs)) {
                    $rawList = (string) $pairs['ServerModList'];
                    break;
                }
            }
            $mode = $this->classifyServerModList($rawList);
            if ($mode === 'ids') {
                $ids = $this->parseWorkshopIdList($rawList);
            }
        }

        $modlistEntries = [];
        if ($this->files->exists($server, self::MODLIST_PATH)) {
            $modlistEntries = $this->parseModlistTxt($this->files->read($server, self::MODLIST_PATH));
        }

        $paks = $this->listPaks($server);

        // If ServerModList is a filename, we only have pak order from modlist.txt
        // Workshop IDs may still be in our manifest
        $manifest = $this->readManifest($server);
        if ($mode !== 'ids' && $ids === [] && $manifest['entries'] !== []) {
            $ids = array_values(array_filter(array_map(
                static fn (array $e): string => (string) ($e['workshop_id'] ?? ''),
                $manifest['entries']
            )));
        }

        $managed = [];
        foreach ($manifest['entries'] ?? [] as $entry) {
            if (is_array($entry) && ! empty($entry['pak_name'])) {
                $managed[(string) $entry['pak_name']] = true;
            }
        }
        foreach ($modlistEntries as $entry) {
            if (! empty($entry['pak_name']) && ($entry['enabled'] ?? true)) {
                $managed[(string) $entry['pak_name']] = true;
            }
        }
        $orphans = [];
        foreach ($paks as $pak) {
            if (! isset($managed[$pak])) {
                $orphans[] = $pak;
            }
        }

        $mountedFromLog = $this->parseMountedPaksFromLog($server);
        $extractInfo = $this->listExtractedServerStems($server);
        $extractedStems = $extractInfo['stems'];
        $platformInfo = app(ConanConfigPlatformService::class)->resolve($server);
        // Soft mount warnings while stopped: ExtractedMods can exist from older boots,
        // so missing extract is not meaningful until the server has run again.
        $serverIsStopped = true;
        try {
            $serverIsStopped = app(PelicanServerStateService::class)->isSafeToEdit($server);
        } catch (\Throwable) {
            $serverIsStopped = true;
        }
        $mountStatus = $this->buildMountStatus(
            $ids,
            $manifest,
            $modlistEntries,
            $paks,
            $mountedFromLog,
            $extractedStems,
            $serverIsStopped,
            (string) $platformInfo['platform'],
        );

        return [
            'workshop_ids' => $ids,
            'server_mod_list_raw' => $rawList,
            'server_mod_list_mode' => $mode,
            'modlist_path' => self::MODLIST_PATH,
            'modlist_entries' => $modlistEntries,
            'paks_on_disk' => $paks,
            'orphan_paks' => $orphans,
            'managed_paks' => array_keys($managed),
            'settings_path' => $settingsPath,
            'config_platform' => (string) $platformInfo['platform'],
            'config_platform_source' => (string) $platformInfo['source'],
            'os_hint' => (string) $platformInfo['os_hint'],
            'manifest' => $manifest,
            'mount_status' => $mountStatus,
            'extracted_by_platform' => $extractInfo['by_platform'],
            'mounted_paks_from_log' => $mountedFromLog,
            'extracted_linux_stems' => $extractedStems,
            'extracted_server_stems' => $extractedStems,
            'modlist_preview' => $this->formatModlistPreview($modlistEntries, $mountStatus),
        ];
    }

    /**
     * Write ordered workshop IDs to ServerModList and update local manifest.
     *
     * @param  list<string>  $workshopIds
     */
    public function saveWorkshopOrder(mixed $server, array $workshopIds, bool $ensureServerModListPointsToIds = true): void
    {
        $ids = $this->normalizeIdList($workshopIds);
        $settingsPath = $this->files->resolveExistingPath(
            $server,
            $this->schema->pathFallbacksForServer($server, 'ServerSettings.ini')
        ) ?? $this->schema->pathForServer($server, 'ServerSettings.ini');

        if (! $this->files->exists($server, $settingsPath)) {
            throw new \RuntimeException('ServerSettings.ini not found');
        }

        $contents = $this->files->read($server, $settingsPath);
        $existingManifest = $this->readManifest($server);
        $pakById = [];
        foreach ($existingManifest['entries'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $wid = (string) ($entry['workshop_id'] ?? '');
            $pak = (string) ($entry['pak_name'] ?? '');
            if ($wid !== '' && $pak !== '') {
                $pakById[$wid] = $pak;
            }
        }

        // Prefer modlist.txt mode once paks/modlist exist so the engine loads files.
        $useModlistFile = $this->files->exists($server, self::MODLIST_PATH)
            || $this->listPaks($server) !== []
            || $pakById !== [];

        $value = $useModlistFile ? 'modlist.txt' : implode(',', $ids);
        $updated = $this->mapper->merge($contents, [
            'ServerModList' => $value,
        ], 'ServerSettings.ini');

        // mapper may type-coerce; force raw string write via parse/sections for this key
        $parsed = $this->mapper->parse($updated, 'ServerSettings.ini');
        $section = 'ServerSettings';
        $parsed['sections'][$section]['ServerModList'] = $value;
        $final = $this->mapper->serialize($parsed, 'ServerSettings.ini');

        $backup = $settingsPath.'.bak-'.gmdate('Ymd-His');
        $this->files->copy($server, $settingsPath, $backup);
        $this->files->write($server, $settingsPath, $final);

        $entries = [];
        foreach ($ids as $i => $id) {
            $entries[] = [
                'order' => $i,
                'workshop_id' => $id,
                'pak_name' => $pakById[$id] ?? null,
                'enabled' => true,
            ];
        }
        $this->writeManifest($server, [
            'version' => 1,
            'updated_at' => gmdate('c'),
            'entries' => $entries,
        ]);

        if ($useModlistFile) {
            $this->writeModlistFromIds($server, $ids, $pakById);
        }

        // Only delete paks that belonged to Workshop IDs *removed* from the load order.
        // Never wipe "unknown" paks or siblings when a partial download/manifest is mid-flight —
        // that previously deleted other mods when install jobs rewrote a single-id list.
        $previousIds = [];
        foreach ($existingManifest['entries'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $wid = (string) ($entry['workshop_id'] ?? '');
            if ($wid !== '') {
                $previousIds[$wid] = true;
            }
        }
        $kept = array_fill_keys($ids, true);
        $toDelete = [];
        foreach ($existingManifest['entries'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $wid = (string) ($entry['workshop_id'] ?? '');
            $pak = (string) ($entry['pak_name'] ?? '');
            if ($wid === '' || $pak === '' || ! str_ends_with(strtolower($pak), '.pak')) {
                continue;
            }
            if (! isset($kept[$wid])) {
                $toDelete[] = $pak;
            }
        }
        $toDelete = array_values(array_unique($toDelete));
        if ($toDelete !== []) {
            $this->deletePaks($server, $toDelete);
            $this->deleteExtractedCaches($server, $toDelete);
        }
    }

    /**
     * Rewrite modlist.txt *pak lines from workshop ID order using known pak names.
     *
     * @param  list<string>  $ids
     * @param  array<string, string>  $pakById
     */
    public function writeModlistFromIds(mixed $server, array $ids, array $pakById = []): void
    {
        $lines = [
            '# Generated by conan-settings-editor',
            '# '.gmdate('c').' order = workshop load order',
        ];
        $paksOnDisk = array_fill_keys($this->listPaks($server), true);
        foreach ($ids as $id) {
            $pak = $pakById[$id] ?? null;
            if ($pak === null || $pak === '') {
                // Keep order visible in UI/file even before Workshop download.
                $lines[] = '# pending workshop '.$id.' (no .pak yet — run Download Workshop paks)';
                continue;
            }
            if ($paksOnDisk !== [] && ! isset($paksOnDisk[$pak])) {
                $lines[] = '# *'.$pak.'  (listed but file not on disk yet)';
            }
            $lines[] = '*'.$pak;
        }
        // Backup then write
        if ($this->files->exists($server, self::MODLIST_PATH)) {
            try {
                $this->files->copy($server, self::MODLIST_PATH, self::MODLIST_PATH.'.bak-'.gmdate('Ymd-His'));
            } catch (\Throwable) {
            }
        }
        $this->files->write($server, self::MODLIST_PATH, implode(PHP_EOL, $lines).PHP_EOL);
    }


    /**
     * Pak basenames currently referenced by the load order (manifest + modlist).
     *
     * @return list<string>
     */
    public function managedPakNames(mixed $server): array
    {
        $names = [];
        $manifest = $this->readManifest($server);
        foreach ($manifest['entries'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pak = (string) ($entry['pak_name'] ?? '');
            if ($pak !== '') {
                $names[$pak] = true;
            }
        }
        if ($this->files->exists($server, self::MODLIST_PATH)) {
            foreach ($this->parseModlistTxt($this->files->read($server, self::MODLIST_PATH)) as $entry) {
                $pak = (string) ($entry['pak_name'] ?? '');
                if ($pak !== '' && ($entry['enabled'] ?? true)) {
                    $names[$pak] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * .pak files present under Mods/ but not in the managed load order.
     *
     * @return list<string>
     */
    public function orphanPaks(mixed $server): array
    {
        $managed = array_fill_keys($this->managedPakNames($server), true);
        $orphans = [];
        foreach ($this->listPaks($server) as $pak) {
            if (! isset($managed[$pak])) {
                $orphans[] = $pak;
            }
        }

        return $orphans;
    }

    /**
     * Delete pak files from Mods/ (basename only).
     *
     * @param  list<string>  $pakNames
     * @return list<string> deleted names
     */
    public function deletePaks(mixed $server, array $pakNames): array
    {
        $names = [];
        foreach ($pakNames as $pak) {
            $pak = basename((string) $pak);
            if ($pak === '' || ! str_ends_with(strtolower($pak), '.pak')) {
                continue;
            }
            $names[] = $pak;
        }
        $names = array_values(array_unique($names));
        if ($names === []) {
            return [];
        }

        $deleted = [];
        try {
            $this->files->delete($server, self::MODS_DIR, $names);
            $deleted = $names;
        } catch (\Throwable $e) {
            report($e);
            foreach ($names as $pak) {
                try {
                    $this->files->delete($server, self::MODS_DIR, [$pak]);
                    $deleted[] = $pak;
                } catch (\Throwable $e2) {
                    report($e2);
                }
            }
        }

        // Confirm against a fresh directory listing (Wings may soft-fail).
        $still = array_fill_keys($this->listPaks($server), true);
        $deleted = array_values(array_filter($deleted, static fn (string $p): bool => ! isset($still[$p])));

        return $deleted;
    }

    /**
     * Remove Saved/ExtractedMods caches for deleted workshop paks (basename without .pak).
     *
     * @param  list<string>  $pakNames
     */
    public function deleteExtractedCaches(mixed $server, array $pakNames): void
    {
        $extractDir = self::EXTRACT_DIR;
        $targets = [];
        foreach ($pakNames as $pak) {
            $base = basename((string) $pak);
            if ($base === '' || ! str_ends_with(strtolower($base), '.pak')) {
                continue;
            }
            $stem = substr($base, 0, -4);
            foreach (['-LinuxServer.pak', '-LinuxServer.utoc', '-LinuxServer.ucas', '-WindowsServer.pak', '-WindowsServer.utoc', '-WindowsServer.ucas'] as $suffix) {
                $targets[] = $stem.$suffix;
            }
        }
        $targets = array_values(array_unique($targets));
        if ($targets === []) {
            return;
        }
        try {
            $this->files->delete($server, $extractDir, $targets);
        } catch (\Throwable $e) {
            // Non-fatal: extract cache may not exist for every platform
            report($e);
        }
    }

    /**
     * Delete every .pak under Mods/ that is not in the current load order.
     *
     * @return list<string>
     */
    public function purgeOrphanPaks(mixed $server): array
    {
        $orphans = $this->orphanPaks($server);
        $deleted = $this->deletePaks($server, $orphans);
        if ($deleted !== []) {
            $this->deleteExtractedCaches($server, $deleted);
        }

        return $deleted;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */

    /**
     * Per-workshop / per-pak mount and readiness report.
     *
     * Status values:
     *  - mounted: last server log mounted this pak
     *  - extracted: server extract exists (engine accepted the pak at least once)
     *  - on_disk: .pak present under Mods/ (and listed) — not yet confirmed mounted
     *  - missing_pak: in load order but no .pak on disk (download needed)
     *  - no_linux_server: only while server is running (or after a boot attempt):
     *      on disk/in list but no mount/extract evidence when other mods did mount/extract
     *      (status id kept for UI badges; label is platform-neutral)
     *  - orphan: .pak on disk but not in load order
     *
     * @param  list<string>  $workshopIds
     * @param  array{version?: int, entries?: list<array<string, mixed>>}  $manifest
     * @param  list<array{line: string, enabled: bool, pak_name: ?string}>  $modlistEntries
     * @param  list<string>  $paksOnDisk
     * @param  list<string>  $mountedFromLog
     * @param  list<string>  $extractedStems
     * @param  bool  $serverIsStopped  When true, never emit no_linux_server (hide until start)
     * @param  string  $configPlatform  LinuxServer|WindowsServer (label wording)
     * @return list<array{
     *   workshop_id: ?string,
     *   pak_name: ?string,
     *   status: string,
     *   label: string,
     *   pak_on_disk: bool,
     *   in_modlist: bool,
     *   mounted_last_boot: bool,
     *   has_linux_extract: bool
     * }>
     */
    public function buildMountStatus(
        array $workshopIds,
        array $manifest,
        array $modlistEntries,
        array $paksOnDisk,
        array $mountedFromLog,
        array $extractedStems,
        bool $serverIsStopped = true,
        string $configPlatform = 'LinuxServer',
    ): array {
        $paks = array_fill_keys($paksOnDisk, true);
        $mounted = array_fill_keys($mountedFromLog, true);
        $extracted = array_fill_keys($extractedStems, true);

        $pakById = [];
        foreach ($manifest['entries'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $wid = (string) ($entry['workshop_id'] ?? '');
            $pak = (string) ($entry['pak_name'] ?? '');
            if ($wid !== '' && $pak !== '') {
                $pakById[$wid] = $pak;
            }
        }

        $inModlist = [];
        foreach ($modlistEntries as $entry) {
            $pak = (string) ($entry['pak_name'] ?? '');
            if ($pak !== '' && ($entry['enabled'] ?? true)) {
                $inModlist[$pak] = true;
            }
        }

        $rows = [];
        $seenPaks = [];
        foreach ($workshopIds as $id) {
            $pak = $pakById[$id] ?? null;
            $onDisk = is_string($pak) && $pak !== '' && isset($paks[$pak]);
            $stem = is_string($pak) && $pak !== '' ? $this->pakStem($pak) : null;
            $hasExtract = $stem !== null && isset($extracted[$stem]);
            $wasMounted = is_string($pak) && $pak !== '' && isset($mounted[$pak]);
            $listed = is_string($pak) && $pak !== '' && isset($inModlist[$pak]);

            if ($pak === null || $pak === '') {
                $status = 'missing_pak';
                $label = 'No .pak yet (download Workshop paks)';
            } elseif ($wasMounted) {
                $status = 'mounted';
                $label = 'Mounted last boot';
            } elseif ($hasExtract) {
                $status = 'extracted';
                $label = 'Server extract present (mounted previously)';
            } elseif ($onDisk && $listed) {
                // While stopped: do not scare operators — extracts from older boots do not
                // mean this (possibly new) mod failed to mount. Hide no_linux_server until start.
                if ($serverIsStopped) {
                    $status = 'on_disk';
                    $label = 'On disk / in modlist — mounts after next server start';
                } elseif ($mounted !== [] && ! $wasMounted && ! $hasExtract) {
                    // Server is up (or was evaluated as not-stopped) and last boot mounted
                    // other mods but not this one, and no Linux extract exists.
                    $status = 'no_linux_server';
                    $label = 'On disk / in modlist — not mounted this boot (no server extract)';
                } elseif ($extracted !== [] && ! $hasExtract) {
                    $status = 'no_linux_server';
                    $label = 'On disk / in modlist — no server extract after start (likely not mounted)';
                } else {
                    $status = 'on_disk';
                    $label = 'On disk — waiting for engine to mount';
                }
            } elseif ($onDisk) {
                $status = 'on_disk';
                $label = 'On disk, not in active modlist.txt';
            } else {
                $status = 'missing_pak';
                $label = 'Listed but .pak file missing';
            }

            if (is_string($pak) && $pak !== '') {
                $seenPaks[$pak] = true;
            }
            $rows[] = [
                'workshop_id' => $id,
                'pak_name' => $pak,
                'status' => $status,
                'label' => $label,
                'pak_on_disk' => $onDisk,
                'in_modlist' => $listed,
                'mounted_last_boot' => $wasMounted,
                'has_linux_extract' => $hasExtract,
            ];
        }

        foreach ($paksOnDisk as $pak) {
            if (isset($seenPaks[$pak])) {
                continue;
            }
            $stem = $this->pakStem($pak);
            $wasMounted = isset($mounted[$pak]);
            $hasExtract = isset($extracted[$stem]);
            $rows[] = [
                'workshop_id' => null,
                'pak_name' => $pak,
                'status' => 'orphan',
                'label' => $wasMounted
                    ? 'Orphan (mounted last boot but not in load order)'
                    : ($hasExtract ? 'Orphan (has Linux extract)' : 'Orphan (not in load order)'),
                'pak_on_disk' => true,
                'in_modlist' => isset($inModlist[$pak]),
                'mounted_last_boot' => $wasMounted,
                'has_linux_extract' => $hasExtract,
            ];
        }

        return $rows;
    }

    /**
     * Paks that LogModManager mounted on the latest server boot (from ConanSandbox.log).
     *
     * @return list<string>
     */
    public function parseMountedPaksFromLog(mixed $server): array
    {
        try {
            if (! $this->files->exists($server, self::LOG_PATH)) {
                return [];
            }
            $contents = $this->files->read($server, self::LOG_PATH);
        } catch (\Throwable) {
            return [];
        }

        return $this->extractMountedPaksFromLogText($contents);
    }

    /**
     * @return list<string>
     */
    public function extractMountedPaksFromLogText(string $contents): array
    {
        $pos = strrpos($contents, 'LogInit: Command Line:');
        if ($pos === false) {
            $pos = strrpos($contents, 'LogPakFile: Initializing PakPlatformFile');
        }
        if ($pos !== false) {
            $contents = substr($contents, $pos);
        }

        $found = [];
        if (preg_match_all(
            '/LogModManager:\\s*Mounting mod pak file:\\s*(.+)/i',
            $contents,
            $matches
        )) {
            foreach ($matches[1] as $path) {
                $base = basename(trim($path));
                if ($base !== '' && str_ends_with(strtolower($base), '.pak')) {
                    $found[$base] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Stems that have LinuxServer IoStore extracts under Saved/ExtractedMods.
     *
     * @return list<string>
     * @deprecated Use listExtractedServerStems() — kept for callers/tests
     */
    public function listExtractedLinuxServerStems(mixed $server): array
    {
        return $this->listExtractedServerStems($server)['stems'];
    }

    /**
     * Stems with LinuxServer and/or WindowsServer IoStore extracts under Saved/ExtractedMods.
     *
     * @return array{stems: list<string>, by_platform: array<string, list<string>>}
     */
    public function listExtractedServerStems(mixed $server): array
    {
        $byPlatform = [
            ConanConfigPlatformService::LINUX => [],
            ConanConfigPlatformService::WINDOWS => [],
        ];
        try {
            $entries = app(\App\Repositories\Daemon\DaemonFileRepository::class)
                ->setServer($server)
                ->getDirectory(self::EXTRACT_DIR);
        } catch (\Throwable) {
            return ['stems' => [], 'by_platform' => $byPlatform];
        }
        if (! is_iterable($entries)) {
            return ['stems' => [], 'by_platform' => $byPlatform];
        }
        $stems = [];
        foreach ($entries as $entry) {
            $name = (string) data_get($entry, 'name');
            if ($name === '' || ! (bool) data_get($entry, 'file', true)) {
                continue;
            }
            if (preg_match('/^(.+)-(LinuxServer|WindowsServer)\.(pak|utoc|ucas)$/i', $name, $m)) {
                $stem = $m[1];
                $plat = $m[2];
                $stems[$stem] = true;
                if (! isset($byPlatform[$plat])) {
                    $byPlatform[$plat] = [];
                }
                if (! in_array($stem, $byPlatform[$plat], true)) {
                    $byPlatform[$plat][] = $stem;
                }
            }
        }

        return [
            'stems' => array_keys($stems),
            'by_platform' => $byPlatform,
        ];
    }

    /**
     * Human-readable modlist.txt preview (includes pending workshop lines + mount tags).
     *
     * @param  list<array{line: string, enabled: bool, pak_name: ?string}>  $modlistEntries
     * @param  list<array<string, mixed>>  $mountStatus
     */
    public function formatModlistPreview(array $modlistEntries, array $mountStatus = []): string
    {
        $lines = [];
        foreach ($modlistEntries as $entry) {
            $line = rtrim((string) ($entry['line'] ?? ''));
            if (trim($line) === '') {
                continue;
            }
            $lines[] = $line;
        }

        $annotations = [];
        foreach ($mountStatus as $row) {
            $pak = (string) ($row['pak_name'] ?? '');
            $status = (string) ($row['status'] ?? '');
            if ($pak !== '' && $status !== '') {
                $annotations[$pak] = $status;
            }
        }

        if ($lines === []) {
            foreach ($mountStatus as $row) {
                if (($row['status'] ?? '') === 'orphan') {
                    continue;
                }
                $pak = $row['pak_name'] ?? null;
                $wid = $row['workshop_id'] ?? null;
                if (is_string($pak) && $pak !== '') {
                    $lines[] = '*'.$pak.'  # '.$row['status'];
                } elseif ($wid) {
                    $lines[] = '# pending workshop '.$wid.' (no pak yet)';
                }
            }
        } else {
            $tagged = [];
            foreach ($lines as $line) {
                $trim = trim($line);
                $pak = $trim;
                if (str_starts_with($pak, '*')) {
                    $pak = substr($pak, 1);
                }
                if (isset($annotations[$pak]) && ! str_contains($line, '#')) {
                    $tagged[] = rtrim($line).'  # '.$annotations[$pak];
                } else {
                    $tagged[] = $line;
                }
            }
            $lines = $tagged;
        }

        return $lines === []
            ? '(file missing or empty — add mods and Save, or Download Workshop paks)'
            : implode("\n", $lines);
    }

    public function pakStem(string $pakName): string
    {
        $base = basename($pakName);
        if (str_ends_with(strtolower($base), '.pak')) {
            return substr($base, 0, -4);
        }

        return $base;
    }

    public function normalizeIdList(array $ids): array
    {
        $out = [];
        $seen = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            // allow steam URLs
            if (preg_match('/id=(\d+)/', $id, $m)) {
                $id = $m[1];
            }
            if ($id === '' || ! ctype_digit($id)) {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function parseWorkshopIdList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        // strip spaces
        $raw = preg_replace('/\s+/', '', $raw) ?? $raw;
        $parts = explode(',', $raw);

        return $this->normalizeIdList($parts);
    }

    public function classifyServerModList(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 'empty';
        }
        $compact = preg_replace('/\s+/', '', $raw) ?? $raw;
        if (preg_match('/^\d+(,\d+)*$/', $compact)) {
            return 'ids';
        }
        if (str_ends_with(strtolower($raw), '.txt') || str_contains($raw, 'modlist')) {
            return 'filename';
        }

        return 'unknown';
    }

    /**
     * @return list<array{line: string, enabled: bool, pak_name: ?string}>
     */
    public function parseModlistTxt(string $contents): array
    {
        $entries = [];
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            $enabled = true;
            if (str_starts_with($trim, '#') || str_starts_with($trim, ';')) {
                $enabled = false;
                $trim = ltrim(substr($trim, 1));
            }
            $pak = $trim;
            if (str_starts_with($pak, '*')) {
                $pak = substr($pak, 1);
            }
            $entries[] = [
                'line' => $line,
                'enabled' => $enabled,
                'pak_name' => $pak !== '' ? $pak : null,
            ];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    public function listPaks(mixed $server): array
    {
        try {
            $entries = app(\App\Repositories\Daemon\DaemonFileRepository::class)
                ->setServer($server)
                ->getDirectory(self::MODS_DIR);
        } catch (\Throwable) {
            return [];
        }
        if (! is_iterable($entries)) {
            return [];
        }
        $paks = [];
        foreach ($entries as $entry) {
            $name = (string) data_get($entry, 'name');
            if ($name !== '' && str_ends_with(strtolower($name), '.pak') && (bool) data_get($entry, 'file', true)) {
                $paks[] = $name;
            }
        }
        sort($paks);

        return $paks;
    }

    /** @return array{version: int, updated_at?: string, entries: list<array<string, mixed>>} */
    public function readManifest(mixed $server): array
    {
        try {
            if (! $this->files->exists($server, self::MANIFEST_PATH)) {
                return ['version' => 1, 'entries' => []];
            }
            $data = json_decode($this->files->read($server, self::MANIFEST_PATH), true);
            if (! is_array($data)) {
                return ['version' => 1, 'entries' => []];
            }
            $data['entries'] = array_values($data['entries'] ?? []);

            return $data;
        } catch (\Throwable) {
            return ['version' => 1, 'entries' => []];
        }
    }

    /** @param  array<string, mixed>  $manifest */
    public function writeManifest(mixed $server, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        try {
            // ensure Mods dir exists by writing the file (Wings may create parents)
            $this->files->write($server, self::MANIFEST_PATH, $json);
        } catch (\Throwable $e) {
            // non-fatal for M1 ID-list mode
            report($e);
        }
    }
}
