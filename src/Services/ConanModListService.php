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
            $this->schema->pathFallbacks('ServerSettings.ini')
        ) ?? (string) $this->schema->pathFor('ServerSettings.ini');

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

        return [
            'workshop_ids' => $ids,
            'server_mod_list_raw' => $rawList,
            'server_mod_list_mode' => $mode,
            'modlist_path' => self::MODLIST_PATH,
            'modlist_entries' => $modlistEntries,
            'paks_on_disk' => $paks,
            'settings_path' => $settingsPath,
            'manifest' => $manifest,
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
            $this->schema->pathFallbacks('ServerSettings.ini')
        ) ?? (string) $this->schema->pathFor('ServerSettings.ini');

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
                // Best-effort: if a single pak contains the id, use it; otherwise skip
                continue;
            }
            if ($paksOnDisk !== [] && ! isset($paksOnDisk[$pak])) {
                // Still list it — install may be mid-flight; engine ignores missing
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
     * @param  list<string>  $ids
     * @return list<string>
     */
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
