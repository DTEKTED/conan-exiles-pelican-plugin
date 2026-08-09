<?php

namespace Dtektion\ConanSettingsEditor\Services;

/**
 * Resolves Conan Dedicated Server config platform (LinuxServer vs WindowsServer).
 *
 * Single plugin for both OS targets: auto-detect from volume files + egg hints,
 * with explicit override via config('conan-settings-editor.config_platform').
 */
class ConanConfigPlatformService
{
    public const LINUX = 'LinuxServer';

    public const WINDOWS = 'WindowsServer';

    /** @return list<string> */
    public function knownPlatforms(): array
    {
        $fromConfig = config('conan-settings-editor.config_platform_fallbacks');
        if (is_array($fromConfig) && $fromConfig !== []) {
            $out = [];
            foreach ($fromConfig as $p) {
                $p = (string) $p;
                if (in_array($p, [self::LINUX, self::WINDOWS], true) && ! in_array($p, $out, true)) {
                    $out[] = $p;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        return [self::LINUX, self::WINDOWS];
    }

    /**
     * Config mode: auto | LinuxServer | WindowsServer
     */
    public function configuredMode(): string
    {
        $mode = (string) (config('conan-settings-editor.config_platform') ?? 'auto');
        $mode = trim($mode);
        if ($mode === '' || strcasecmp($mode, 'auto') === 0) {
            return 'auto';
        }
        if (strcasecmp($mode, self::LINUX) === 0 || strcasecmp($mode, 'linux') === 0) {
            return self::LINUX;
        }
        if (strcasecmp($mode, self::WINDOWS) === 0 || strcasecmp($mode, 'windows') === 0) {
            return self::WINDOWS;
        }

        return 'auto';
    }

    /**
     * @return array{
     *   platform: string,
     *   source: string,
     *   mode: string,
     *   existing: array<string, bool>,
     *   os_hint: string
     * }
     */
    public function resolve(mixed $server): array
    {
        $mode = $this->configuredMode();
        $existing = $this->probeExistingPlatforms($server);
        $eggHint = $this->eggOsHint($server);

        if ($mode === self::LINUX || $mode === self::WINDOWS) {
            return [
                'platform' => $mode,
                'source' => 'config',
                'mode' => $mode,
                'existing' => $existing,
                'os_hint' => $mode === self::WINDOWS ? 'windows' : 'linux',
            ];
        }

        // auto: prefer a platform that already has ServerSettings.ini
        $linuxExists = (bool) ($existing[self::LINUX] ?? false);
        $windowsExists = (bool) ($existing[self::WINDOWS] ?? false);

        if ($linuxExists && ! $windowsExists) {
            return [
                'platform' => self::LINUX,
                'source' => 'auto-existing-linux',
                'mode' => 'auto',
                'existing' => $existing,
                'os_hint' => 'linux',
            ];
        }
        if ($windowsExists && ! $linuxExists) {
            return [
                'platform' => self::WINDOWS,
                'source' => 'auto-existing-windows',
                'mode' => 'auto',
                'existing' => $existing,
                'os_hint' => 'windows',
            ];
        }
        if ($linuxExists && $windowsExists) {
            // Both present: egg hint, else prefer Linux (primary target for this project)
            if ($eggHint === 'windows') {
                return [
                    'platform' => self::WINDOWS,
                    'source' => 'auto-both-egg-windows',
                    'mode' => 'auto',
                    'existing' => $existing,
                    'os_hint' => 'windows',
                ];
            }

            return [
                'platform' => self::LINUX,
                'source' => 'auto-both-prefer-linux',
                'mode' => 'auto',
                'existing' => $existing,
                'os_hint' => $eggHint === 'windows' ? 'windows' : 'linux',
            ];
        }

        // Neither exists yet (fresh install): egg hint, else Linux default
        if ($eggHint === 'windows') {
            return [
                'platform' => self::WINDOWS,
                'source' => 'auto-default-egg-windows',
                'mode' => 'auto',
                'existing' => $existing,
                'os_hint' => 'windows',
            ];
        }

        return [
            'platform' => self::LINUX,
            'source' => 'auto-default-linux',
            'mode' => 'auto',
            'existing' => $existing,
            'os_hint' => 'linux',
        ];
    }

    public function platform(mixed $server): string
    {
        return $this->resolve($server)['platform'];
    }

    public function osHint(mixed $server): string
    {
        return $this->resolve($server)['os_hint'];
    }

    /**
     * Ordered path candidates for a config file basename (e.g. ServerSettings.ini).
     *
     * @return list<string>
     */
    public function pathCandidates(mixed $server, string $file): array
    {
        $file = basename($file);
        if ($file === 'modlist.txt') {
            return ['ConanSandbox/Mods/modlist.txt'];
        }

        $resolved = $this->resolve($server);
        $preferred = $resolved['platform'];
        $order = [$preferred];
        foreach ($this->knownPlatforms() as $p) {
            if ($p !== $preferred) {
                $order[] = $p;
            }
        }

        $paths = [];
        foreach ($order as $platform) {
            $paths[] = 'ConanSandbox/Saved/Config/'.$platform.'/'.$file;
        }

        // Include schema fallbacks (if any) without duplicating
        try {
            $schema = app(ConanSettingsSchema::class);
            foreach ($schema->rawPathFallbacks($file) as $extra) {
                if (is_string($extra) && $extra !== '' && ! in_array($extra, $paths, true)) {
                    $paths[] = $extra;
                }
            }
        } catch (\Throwable) {
        }

        return $paths;
    }

    public function primaryPath(mixed $server, string $file): string
    {
        $candidates = $this->pathCandidates($server, $file);

        return $candidates[0] ?? ('ConanSandbox/Saved/Config/'.self::LINUX.'/'.basename($file));
    }

    /**
     * @return array<string, bool> platform => ServerSettings.ini exists
     */
    public function probeExistingPlatforms(mixed $server): array
    {
        $files = app(ConanSettingsFileService::class);
        $out = [];
        foreach ($this->knownPlatforms() as $platform) {
            $path = 'ConanSandbox/Saved/Config/'.$platform.'/ServerSettings.ini';
            try {
                $out[$platform] = $files->exists($server, $path);
            } catch (\Throwable) {
                $out[$platform] = false;
            }
        }

        return $out;
    }

    /**
     * Best-effort egg/image hint: linux | windows | unknown
     */
    public function eggOsHint(mixed $server): string
    {
        if (ConanServerDetector::suggestsWindows($server)) {
            return 'windows';
        }
        if (ConanServerDetector::suggestsLinux($server)) {
            return 'linux';
        }

        return 'unknown';
    }
}
