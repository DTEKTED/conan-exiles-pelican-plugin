<?php

namespace Dtektion\ConanSettingsEditor\Services;

use Throwable;

class ConanServerDetector
{
    public static function isConanServer(mixed $server): bool
    {
        if (! is_object($server)) {
            return false;
        }

        if (method_exists($server, 'loadMissing')) {
            try {
                $server->loadMissing('egg');
            } catch (Throwable) {
            }
        }

        $tags = data_get($server, 'egg.tags');
        if (is_iterable($tags)) {
            foreach ($tags as $tag) {
                if (is_string($tag) && (str_contains(strtolower($tag), 'conan') || str_contains(strtolower($tag), 'exiles'))) {
                    return true;
                }
            }
        }

        foreach (self::haystacks($server) as $value) {
            $value = strtolower($value);
            if (str_contains($value, 'conan') || str_contains($value, 'exiles')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Egg/image/startup looks Windows-oriented (best-effort).
     */
    public static function suggestsWindows(mixed $server): bool
    {
        foreach (self::haystacks($server) as $value) {
            $v = strtolower($value);
            if (
                str_contains($v, 'windowsserver')
                || str_contains($v, 'windows-server')
                || str_contains($v, 'conan-windows')
                || str_contains($v, 'servercore')
                || (str_contains($v, 'windows') && str_contains($v, 'conan'))
                || str_contains($v, 'wine')
            ) {
                return true;
            }
        }
        $tags = data_get($server, 'egg.tags');
        if (is_iterable($tags)) {
            foreach ($tags as $tag) {
                if (! is_string($tag)) {
                    continue;
                }
                $t = strtolower($tag);
                if (str_contains($t, 'windows') || str_contains($t, 'conan-windows')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Egg/image looks Linux-oriented (best-effort).
     */
    public static function suggestsLinux(mixed $server): bool
    {
        foreach (self::haystacks($server) as $value) {
            $v = strtolower($value);
            if (
                str_contains($v, 'linuxserver')
                || str_contains($v, 'linux-server')
                || str_contains($v, 'conan-linux')
                || (str_contains($v, 'linux') && str_contains($v, 'conan'))
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function haystacks(mixed $server): array
    {
        if (is_object($server) && method_exists($server, 'loadMissing')) {
            try {
                $server->loadMissing('egg');
            } catch (Throwable) {
            }
        }

        $haystacks = [
            data_get($server, 'egg.name'),
            data_get($server, 'egg.startup'),
            data_get($server, 'startup'),
            data_get($server, 'image'),
            data_get($server, 'egg.docker_image'),
        ];
        $dockerImages = data_get($server, 'egg.docker_images');
        if (is_iterable($dockerImages)) {
            foreach ($dockerImages as $image) {
                $haystacks[] = $image;
            }
        }
        $out = [];
        foreach ($haystacks as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }
}
