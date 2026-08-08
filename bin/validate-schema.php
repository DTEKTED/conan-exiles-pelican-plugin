<?php

/**
 * Validate schema integrity and optional live INI round-trip.
 *
 * Usage (inside panel container):
 *   php plugins/conan-settings-editor/bin/validate-schema.php
 *   php plugins/conan-settings-editor/bin/validate-schema.php /path/to/ServerSettings.ini
 */

$pluginRoot = dirname(__DIR__);
$schemaPath = $pluginRoot.'/resources/schema/server-settings.schema.json';

spl_autoload_register(function ($class) use ($pluginRoot) {
    $prefix = 'Dtektion\\ConanSettingsEditor\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $pluginRoot.'/src/'.$relative.'.php';
    if (is_readable($file)) {
        require $file;
    }
});

if (! function_exists('plugin_path')) {
    function plugin_path(string $id, string $path = ''): string
    {
        $base = dirname(__DIR__);
        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }
}
if (! function_exists('config')) {
    function config($key = null, $default = null)
    {
        return $default;
    }
}

use Dtektion\ConanSettingsEditor\Services\ConanIniMapper;
use Dtektion\ConanSettingsEditor\Services\ConanSettingsSchema;

$schema = ConanSettingsSchema::load($schemaPath);
$errors = $schema->validate();
$stats = $schema->stats();

echo "schema_version=".$schema->version()."\n";
echo "fields=".$stats['field_count']." present_on_live=".$stats['present_on_live']."\n";
echo "schema_validate_errors=".count($errors)."\n";
foreach ($errors as $e) {
    echo "  ERROR: {$e}\n";
}

$iniPath = $argv[1] ?? null;
if ($iniPath === null) {
    exit(count($errors) > 0 ? 1 : 0);
}

if (! is_readable($iniPath)) {
    fwrite(STDERR, "INI not readable: {$iniPath}\n");
    exit(2);
}

$raw = file_get_contents($iniPath);
$mapper = new ConanIniMapper($schema);
$parsed = $mapper->parse($raw, 'ServerSettings.ini');
$index = $schema->iniKeyIndex('ServerSettings.ini');

$decodeEncodeMismatches = 0;
foreach ($parsed['sections'] as $section => $pairs) {
    foreach ($pairs as $key => $rawValue) {
        $field = $index[$key] ?? null;
        if ($field === null) {
            continue;
        }
        $typed = $mapper->decodeValue($rawValue, $field);
        $encoded = $mapper->encodeValue($typed, $field);
        if ($field['ini_style'] === 'float' && is_numeric($rawValue) && is_numeric($encoded)) {
            if (abs((float) $rawValue - (float) $encoded) > 0.000001) {
                echo "  FLOAT_MISMATCH {$key}: {$rawValue} -> {$encoded}\n";
                $decodeEncodeMismatches++;
            }
            continue;
        }
        if ((string) $encoded !== (string) $rawValue) {
            echo "  ENCODE_MISMATCH {$key}: [{$rawValue}] -> [{$encoded}] type={$field['type']} style={$field['ini_style']}\n";
            $decodeEncodeMismatches++;
        }
    }
}

$merged = $mapper->merge($raw, [], 'ServerSettings.ini');
$reparsed = $mapper->parse($merged, 'ServerSettings.ini');
foreach ($parsed['sections'] as $section => $pairs) {
    foreach ($pairs as $key => $rawValue) {
        $new = $reparsed['sections'][$section][$key] ?? null;
        if ($new !== $rawValue) {
            echo "  MERGE_NOOP_MISMATCH {$section}.{$key}: [{$rawValue}] vs [{$new}]\n";
            $decodeEncodeMismatches++;
        }
    }
}

$mode = $mapper->detectMode($parsed['typed']);
echo "detected_mode=".($mode ?? 'null')."\n";
echo "unknown_keys=".count($parsed['unknown'])."\n";
echo "typed_keys=".count($parsed['typed'])."\n";
echo "roundtrip_mismatches={$decodeEncodeMismatches}\n";

exit((count($errors) + $decodeEncodeMismatches) > 0 ? 1 : 0);
