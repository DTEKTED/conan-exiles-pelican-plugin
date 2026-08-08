<?php

namespace Dtektion\ConanSettingsEditor\Services;

/**
 * Bidirectional mapping between Conan INI text and typed PHP values
 * using the authoritative schema (ini_style per field).
 *
 * This is the critical correctness layer: parse must be lossless for unknown
 * keys/comments where possible, and serialize must preserve Funcom styles
 * (True/False vs 0/1 vs floats).
 */
class ConanIniMapper
{
    public function __construct(
        private readonly ConanSettingsSchema $schema
    ) {}

    /**
     * Parse an INI document into structured data.
     *
     * @return array{
     *   sections: array<string, array<string, string>>,
     *   order: list<array{type: string, value?: string, section?: string, key?: string, raw?: string}>,
     *   typed: array<string, mixed>,
     *   unknown: array<string, string>
     * }
     */
    public function parse(string $contents, string $file = 'ServerSettings.ini'): array
    {
        $sections = [];
        $order = [];
        $current = 'ServerSettings';
        $index = $this->schema->iniKeyIndex($file);

        foreach (preg_split("/\r\n|\n|\r/", $contents) as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $order[] = ['type' => 'blank', 'raw' => $line];
                continue;
            }
            if (str_starts_with($trim, ';') || str_starts_with($trim, '#')) {
                $order[] = ['type' => 'comment', 'raw' => $line];
                continue;
            }
            if (str_starts_with($trim, '[') && str_ends_with($trim, ']')) {
                $current = substr($trim, 1, -1);
                $sections[$current] ??= [];
                $order[] = ['type' => 'section', 'section' => $current, 'raw' => $line];
                continue;
            }
            if (! str_contains($trim, '=')) {
                $order[] = ['type' => 'raw', 'raw' => $line];
                continue;
            }
            [$key, $value] = explode('=', $trim, 2);
            $key = trim($key);
            // Keep value as raw right-hand side (no trim of inner spaces beyond ends)
            $value = trim($value);
            $sections[$current][$key] = $value;
            $order[] = [
                'type' => 'pair',
                'section' => $current,
                'key' => $key,
                'value' => $value,
                'raw' => $line,
            ];
        }

        $typed = [];
        $unknown = [];
        foreach ($sections as $section => $pairs) {
            foreach ($pairs as $key => $rawValue) {
                $field = $index[$key] ?? null;
                if ($field === null) {
                    $unknown["{$section}.{$key}"] = $rawValue;
                    continue;
                }
                $typed[$key] = $this->decodeValue($rawValue, $field);
            }
        }

        return [
            'sections' => $sections,
            'order' => $order,
            'typed' => $typed,
            'unknown' => $unknown,
        ];
    }

    /**
     * Merge typed updates into an existing INI document, preserving unknown keys
     * and comments when rewriting in original order.
     *
     * @param  array<string, mixed>  $updates  ini_key => typed value
     */
    public function merge(string $contents, array $updates, string $file = 'ServerSettings.ini'): string
    {
        $parsed = $this->parse($contents, $file);
        $index = $this->schema->iniKeyIndex($file);
        $readOnly = array_flip($this->schema->readOnlyIniKeys());

        // Apply updates into sections map
        $sectionForKey = [];
        foreach ($index as $iniKey => $field) {
            $sectionForKey[$iniKey] = $field['section'] ?? 'ServerSettings';
        }

        foreach ($updates as $iniKey => $typedValue) {
            if (isset($readOnly[$iniKey])) {
                continue;
            }
            $field = $index[$iniKey] ?? null;
            if ($field === null) {
                // allow writing unknown only if explicitly string
                continue;
            }
            if (($field['editable'] ?? true) === false || ($field['read_only'] ?? false) === true) {
                continue;
            }
            $section = $field['section'] ?? 'ServerSettings';
            $parsed['sections'][$section][$iniKey] = $this->encodeValue($typedValue, $field);
        }

        return $this->serialize($parsed, $file);
    }

    /**
     * Serialize parsed structure back to INI text.
     * Prefer original order; append new known keys at end of their section.
     *
     * @param  array{sections: array, order: list, typed?: array, unknown?: array}  $parsed
     */
    public function serialize(array $parsed, string $file = 'ServerSettings.ini'): string
    {
        $sections = $parsed['sections'];
        $order = $parsed['order'];
        $written = []; // section.key
        $lines = [];
        $currentSection = null;

        foreach ($order as $item) {
            $type = $item['type'];
            if ($type === 'blank' || $type === 'comment' || $type === 'raw') {
                $lines[] = $item['raw'] ?? '';
                continue;
            }
            if ($type === 'section') {
                $currentSection = $item['section'];
                $lines[] = $item['raw'] ?? ("[{$currentSection}]");
                continue;
            }
            if ($type === 'pair') {
                $section = $item['section'];
                $key = $item['key'];
                $currentSection = $section;
                if (! array_key_exists($key, $sections[$section] ?? [])) {
                    // deleted — skip
                    continue;
                }
                $lines[] = $key.'='.$sections[$section][$key];
                $written["{$section}.{$key}"] = true;
            }
        }

        // Append new keys not in original order
        foreach ($sections as $section => $pairs) {
            $missing = [];
            foreach ($pairs as $key => $value) {
                if (! isset($written["{$section}.{$key}"])) {
                    $missing[$key] = $value;
                }
            }
            if ($missing === []) {
                continue;
            }
            // ensure section header exists
            $hasSection = false;
            foreach ($lines as $line) {
                if (trim($line) === "[{$section}]") {
                    $hasSection = true;
                    break;
                }
            }
            if (! $hasSection) {
                if ($lines !== [] && end($lines) !== '') {
                    $lines[] = '';
                }
                $lines[] = "[{$section}]";
            }
            foreach ($missing as $key => $value) {
                $lines[] = $key.'='.$value;
            }
        }

        $out = implode("\n", $lines);
        if (! str_ends_with($out, "\n")) {
            $out .= "\n";
        }

        return $out;
    }

    /**
     * Encode a typed PHP value to INI RHS string using field schema.
     */
    public function encodeValue(mixed $value, array $field): string
    {
        $style = $field['ini_style'] ?? 'string';
        $type = $field['type'] ?? 'string';

        // Prefer live style when provided on field for this host
        if (($field['present_on_live'] ?? false) && isset($field['live_value'])) {
            $live = (string) $field['live_value'];
            if ($live === 'True' || $live === 'False') {
                $style = 'TrueFalse';
            } elseif (preg_match('/^-?\d+$/', $live)) {
                $style = 'int';
            } elseif (preg_match('/^-?\d+\.\d+$/', $live)) {
                $style = 'float';
            }
        }

        return match ($style) {
            'TrueFalse' => $this->toBool($value) ? 'True' : 'False',
            'int' => (string) (int) round((float) $value),
            'float' => $this->formatFloat($value),
            default => match ($type) {
                'boolean' => $this->toBool($value) ? 'True' : 'False',
                'integer' => (string) (int) round((float) $value),
                'float' => $this->formatFloat($value),
                default => (string) $value,
            },
        };
    }

    /**
     * Decode INI RHS string to typed PHP value.
     */
    public function decodeValue(string $raw, array $field): mixed
    {
        $style = $field['ini_style'] ?? null;
        $type = $field['type'] ?? 'string';

        if ($style === 'TrueFalse' || ($type === 'boolean' && in_array($raw, ['True', 'False', 'true', 'false'], true))) {
            return in_array($raw, ['True', 'true', '1'], true);
        }
        if ($style === 'int' || $type === 'integer') {
            if (is_numeric($raw)) {
                return (int) $raw;
            }

            return $raw;
        }
        if ($style === 'float' || $type === 'float') {
            if (is_numeric($raw)) {
                return (float) $raw;
            }

            return $raw;
        }

        return $raw;
    }

    /**
     * Apply a mode preset (pve|pve-c|pvp) onto typed values array.
     *
     * @param  array<string, mixed>  $typed
     * @return array<string, mixed>
     */
    public function applyModePreset(array $typed, string $mode): array
    {
        $presets = $this->schema->modePresets();
        if (! isset($presets[$mode]['values'])) {
            throw new \InvalidArgumentException("Unknown mode preset: {$mode}");
        }
        foreach ($presets[$mode]['values'] as $iniKey => $spec) {
            $typed[$iniKey] = $spec['value'];
        }

        return $typed;
    }

    /**
     * Detect combat mode from typed ServerSettings values.
     */
    public function detectMode(array $typed): ?string
    {
        $pvp = $typed['PVPEnabled'] ?? null;
        $mod = $typed['CombatModeModifier'] ?? null;
        $structures = $typed['CanDamagePlayerOwnedStructures'] ?? null;

        $pvpBool = is_bool($pvp) ? $pvp : in_array((string) $pvp, ['True', 'true', '1'], true);
        $modInt = is_numeric($mod) ? (int) $mod : null;
        $structBool = is_bool($structures)
            ? $structures
            : in_array((string) $structures, ['True', 'true', '1'], true);

        if ($pvpBool === false) {
            return 'pve';
        }
        if ($pvpBool === true && $modInt === 1) {
            return 'pve-c';
        }
        if ($pvpBool === true && ($modInt === 0 || $modInt === null) && $structBool === true) {
            return 'pvp';
        }
        if ($pvpBool === true && $modInt === 0) {
            return 'pvp'; // structures may still be false on some servers
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        $s = strtolower((string) $value);

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private function formatFloat(mixed $value): string
    {
        $f = (float) $value;
        // Keep compact but stable representation
        $s = rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
        if ($s === '' || $s === '-') {
            return '0';
        }

        return $s;
    }
}
