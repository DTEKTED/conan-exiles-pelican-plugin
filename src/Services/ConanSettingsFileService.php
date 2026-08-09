<?php

namespace Dtektion\ConanSettingsEditor\Services;

use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Throwable;

class ConanSettingsFileService
{
    public function __construct(
        private readonly DaemonFileRepository $fileRepository,
    ) {}

    public function exists(mixed $server, string $path): bool
    {
        try {
            $content = $this->fileRepository->setServer($server)->getContent($path);

            return is_string($content);
        } catch (Exception) {
            return false;
        }
    }

    public function read(mixed $server, string $path): string
    {
        return $this->fileRepository->setServer($server)->getContent($path);
    }

    public function write(mixed $server, string $path, string $contents): void
    {
        $response = $this->fileRepository->setServer($server)->putContent($path, $contents);
        if ($response->failed()) {
            throw new Exception('Failed to write Conan settings file.');
        }
    }

    public function copy(mixed $server, string $from, string $to): void
    {
        $this->write($server, $to, $this->read($server, $from));
    }

    /**
     * Delete files under a directory via Wings (names relative to $root).
     *
     * @param  list<string>  $names
     */
    public function delete(mixed $server, string $root, array $names): void
    {
        $names = array_values(array_filter(array_map(static fn ($n) => basename((string) $n), $names)));
        if ($names === []) {
            return;
        }
        $response = $this->fileRepository->setServer($server)->deleteFiles($root === '' ? '/' : $root, $names);
        if ($response->failed()) {
            throw new Exception('Failed to delete file(s): '.implode(', ', $names));
        }
    }

    /**
     * @return array<int, array{name: string, path: string, size: mixed, modified: mixed}>
     */
    public function listBackups(mixed $server, string $settingsPath): array
    {
        $directory = $this->directoryOf($settingsPath);
        $prefix = basename($settingsPath).'.bak-';
        try {
            $entries = $this->fileRepository->setServer($server)->getDirectory($directory === '' ? '/' : $directory);
        } catch (Throwable) {
            return [];
        }
        if (! is_iterable($entries)) {
            return [];
        }
        $backups = [];
        foreach ($entries as $entry) {
            $name = (string) data_get($entry, 'name');
            if ($name === '' || ! str_starts_with($name, $prefix)) {
                continue;
            }
            if (! (bool) data_get($entry, 'file', true)) {
                continue;
            }
            $backups[] = [
                'name' => $name,
                'path' => ($directory === '' ? '' : $directory.'/').$name,
                'size' => data_get($entry, 'size'),
                'modified' => data_get($entry, 'modified') ?? data_get($entry, 'modified_at'),
            ];
        }
        usort($backups, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $backups;
    }

    public function resolveExistingPath(mixed $server, array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if ($this->exists($server, $path)) {
                return $path;
            }
        }

        return $candidates[0] ?? null;
    }

    private function directoryOf(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? '' : substr($path, 0, $position);
    }
}
