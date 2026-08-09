<?php

namespace Dtektion\ConanSettingsEditor\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Loads and queries the authoritative Conan settings schema.
 *
 * Schema is the single source of truth for file/section/ini_key/type/ini_style/group.
 * Built offline from balnaimi/conan-exiles-server requirements mapping + live server keys.
 */
class ConanSettingsSchema
{
    private array $schema;

    private array $fieldsById = [];

    private array $fieldsByIniKey = [];

    private array $fieldsByEnvVar = [];

    public function __construct(?string $schemaPath = null)
    {
        $path = $schemaPath
            ?: config('conan-settings-editor.schema_path')
            ?: plugin_path('conan-settings-editor', 'resources/schema/server-settings.schema.json');

        if (! is_readable($path)) {
            throw new RuntimeException("Conan settings schema not readable: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || empty($decoded['fields'])) {
            throw new RuntimeException('Conan settings schema is missing fields[]');
        }

        $this->schema = $decoded;
        $this->index();
    }

    public static function load(?string $schemaPath = null): self
    {
        return new self($schemaPath);
    }

    public function version(): int
    {
        return (int) ($this->schema['schema_version'] ?? 0);
    }

    public function raw(): array
    {
        return $this->schema;
    }

    public function stats(): array
    {
        return $this->schema['stats'] ?? [];
    }

    public function paths(): array
    {
        return $this->schema['paths'] ?? [];
    }

    public function pathFor(string $file): ?string
    {
        return $this->paths()[$file] ?? null;
    }

    public function pathFallbacks(string $file): array
    {
        return $this->rawPathFallbacks($file);
    }

    /**
     * Schema-declared fallbacks only (no platform reordering).
     *
     * @return list<string>
     */
    public function rawPathFallbacks(string $file): array
    {
        $listed = $this->schema['path_fallbacks'][$file] ?? null;
        if (is_array($listed) && $listed !== []) {
            return array_values(array_filter(array_map('strval', $listed)));
        }

        return array_values(array_filter([
            $this->pathFor($file),
        ]));
    }

    /**
     * Platform-aware ordered candidates for a live server volume.
     *
     * @return list<string>
     */
    public function pathFallbacksForServer(mixed $server, string $file): array
    {
        return app(ConanConfigPlatformService::class)->pathCandidates($server, $file);
    }

    public function pathForServer(mixed $server, string $file): string
    {
        return app(ConanConfigPlatformService::class)->primaryPath($server, $file);
    }

    public function groups(): array
    {
        return $this->schema['groups'] ?? [];
    }

    public function modePresets(): array
    {
        return $this->schema['mode_presets'] ?? [];
    }

    public function serialization(): array
    {
        return $this->schema['serialization'] ?? [];
    }

    public function readOnlyIniKeys(): array
    {
        return $this->schema['read_only_ini_keys'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function fields(): array
    {
        return array_values($this->fieldsById);
    }

    public function field(string $id): ?array
    {
        return $this->fieldsById[$id] ?? null;
    }

    /**
     * First field matching ini key (optionally constrained to file).
     *
     * @return array<string, mixed>|null
     */
    public function fieldByIniKey(string $iniKey, ?string $file = null): ?array
    {
        $candidates = $this->fieldsByIniKey[$iniKey] ?? [];
        if ($file === null) {
            return $candidates[0] ?? null;
        }
        foreach ($candidates as $field) {
            if (($field['file'] ?? null) === $file) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldsForFile(string $file): array
    {
        return array_values(array_filter(
            $this->fields(),
            static fn (array $f): bool => ($f['file'] ?? null) === $file
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldsForGroup(string $group): array
    {
        return array_values(array_filter(
            $this->fields(),
            static fn (array $f): bool => ($f['group'] ?? null) === $group
        ));
    }

    /**
     * Map of ini_key => field for a single file (ServerSettings.ini by default).
     *
     * @return array<string, array<string, mixed>>
     */
    public function iniKeyIndex(?string $file = 'ServerSettings.ini'): array
    {
        $out = [];
        foreach ($this->fields() as $field) {
            if ($file !== null && ($field['file'] ?? null) !== $file) {
                continue;
            }
            $out[$field['ini_key']] = $field;
        }

        return $out;
    }

    /**
     * Env var → field mapping (from balnaimi requirements map; not required at runtime).
     *
     * @return array<string, array<string, mixed>>
     */
    public function envVarIndex(): array
    {
        return $this->fieldsByEnvVar;
    }

    /**
     * Validate schema internal consistency.
     *
     * @return list<string> error messages (empty = ok)
     */
    public function validate(): array
    {
        $errors = [];
        $ids = [];
        // Validate against raw schema entries so duplicates are not hidden by fieldsById collapse
        $rawFields = is_array($this->schema['fields'] ?? null) ? $this->schema['fields'] : $this->fields();
        foreach ($rawFields as $field) {
            if (! is_array($field)) {
                $errors[] = 'Non-array field entry in schema';
                continue;
            }
            foreach (['id', 'file', 'section', 'ini_key', 'type', 'group', 'ini_style'] as $required) {
                if (! array_key_exists($required, $field) || $field[$required] === '' || $field[$required] === null) {
                    $errors[] = "Field missing {$required}: ".json_encode($field['ini_key'] ?? $field);
                }
            }
            $id = $field['id'] ?? null;
            if ($id !== null) {
                if (isset($ids[$id])) {
                    $errors[] = "Duplicate field id: {$id}";
                }
                $ids[$id] = true;
            }
            $style = $field['ini_style'] ?? null;
            $type = $field['type'] ?? null;
            if ($style === 'TrueFalse' && $type !== 'boolean') {
                $errors[] = "ini_style TrueFalse requires boolean type for {$field['ini_key']}";
            }
            if ($style === 'int' && ! in_array($type, ['integer', 'password'], true)) {
                // password should not be int
                if ($type !== 'integer') {
                    $errors[] = "ini_style int requires integer type for {$field['ini_key']} (got {$type})";
                }
            }
        }

        foreach ($this->modePresets() as $mode => $preset) {
            foreach ($preset['values'] ?? [] as $iniKey => $spec) {
                if ($this->fieldByIniKey($iniKey, $spec['file'] ?? 'ServerSettings.ini') === null) {
                    $errors[] = "Mode preset {$mode} references unknown ini_key {$iniKey}";
                }
            }
        }

        return $errors;
    }

    private function index(): void
    {
        foreach ($this->schema['fields'] as $field) {
            if (! is_array($field) || empty($field['id']) || empty($field['ini_key'])) {
                throw new InvalidArgumentException('Invalid schema field entry');
            }
            if (isset($this->fieldsById[$field['id']])) {
                throw new InvalidArgumentException('Duplicate schema field id: '.$field['id']);
            }
            $this->fieldsById[$field['id']] = $field;
            $this->fieldsByIniKey[$field['ini_key']][] = $field;
            if (! empty($field['env_var'])) {
                $this->fieldsByEnvVar[$field['env_var']] = $field;
            }
        }
    }
}
