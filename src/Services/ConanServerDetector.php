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

        foreach ($haystacks as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $value = strtolower($value);
            if (str_contains($value, 'conan') || str_contains($value, 'exiles')) {
                return true;
            }
        }

        return false;
    }
}
