<?php

namespace Dtektion\ConanSettingsEditor\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches public Steam Workshop metadata for Conan Exiles (app 440900).
 */
class SteamWorkshopService
{
    public const APP_ID = 440900;

    /**
     * @param  list<string|int>  $ids
     * @return array<string, array{
     *   id: string,
     *   result: int,
     *   title: ?string,
     *   description: ?string,
     *   preview_url: ?string,
     *   file_size: ?int,
     *   time_updated: ?int,
     *   banned: bool,
     *   url: string,
     *   error: ?string
     * }>
     */
    public function getDetails(array $ids, bool $useCache = true): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && ctype_digit($id)) {
                $normalized[$id] = $id;
            }
        }
        $normalized = array_values($normalized);
        if ($normalized === []) {
            return [];
        }

        $out = [];
        $missing = [];
        foreach ($normalized as $id) {
            if ($useCache) {
                $cached = Cache::get($this->cacheKey($id));
                if (is_array($cached)) {
                    $out[$id] = $cached;
                    continue;
                }
            }
            $missing[] = $id;
        }

        if ($missing !== []) {
            try {
                $fetched = $this->fetchPublishedFileDetails($missing);
                foreach ($missing as $id) {
                    $row = $fetched[$id] ?? $this->emptyDetail($id, 'No data returned from Steam');
                    Cache::put($this->cacheKey($id), $row, now()->addHours(6));
                    $out[$id] = $row;
                }
            } catch (Throwable $e) {
                report($e);
                foreach ($missing as $id) {
                    $out[$id] = $this->emptyDetail($id, $e->getMessage());
                }
            }
        }

        // Preserve request order
        $ordered = [];
        foreach ($normalized as $id) {
            $ordered[$id] = $out[$id];
        }

        return $ordered;
    }

    public function getOne(string $id, bool $useCache = true): array
    {
        $all = $this->getDetails([$id], $useCache);

        return $all[$id] ?? $this->emptyDetail($id, 'Unknown');
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function fetchPublishedFileDetails(array $ids): array
    {
        $form = ['itemcount' => count($ids)];
        foreach (array_values($ids) as $i => $id) {
            $form["publishedfileids[{$i}]"] = $id;
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post('https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/', $form);

        if (! $response->successful()) {
            throw new \RuntimeException('Steam Workshop API HTTP '.$response->status());
        }

        $details = data_get($response->json(), 'response.publishedfiledetails', []);
        $out = [];
        foreach ($details as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (string) ($item['publishedfileid'] ?? '');
            if ($id === '') {
                continue;
            }
            $result = (int) ($item['result'] ?? 0);
            $out[$id] = [
                'id' => $id,
                'result' => $result,
                'title' => $result === 1 ? ($item['title'] ?? null) : null,
                'description' => $result === 1 ? $this->shortDescription((string) ($item['description'] ?? '')) : null,
                'preview_url' => $result === 1 ? ($item['preview_url'] ?? null) : null,
                'file_size' => isset($item['file_size']) && is_numeric($item['file_size']) ? (int) $item['file_size'] : null,
                'time_updated' => isset($item['time_updated']) ? (int) $item['time_updated'] : null,
                'banned' => (bool) ($item['banned'] ?? false),
                'url' => "https://steamcommunity.com/sharedfiles/filedetails/?id={$id}",
                'error' => $result === 1 ? null : 'Steam result code '.$result,
            ];
        }

        return $out;
    }

    private function shortDescription(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['[h1]', '[/h1]', '[b]', '[/b]', '[list]', '[/list]', '[*]', "\r"], ['', '', '', '', '', '', '• ', ''], $html)));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);
        if (strlen($text) > 280) {
            return substr($text, 0, 277).'…';
        }

        return $text;
    }

    private function emptyDetail(string $id, string $error): array
    {
        return [
            'id' => $id,
            'result' => 0,
            'title' => null,
            'description' => null,
            'preview_url' => null,
            'file_size' => null,
            'time_updated' => null,
            'banned' => false,
            'url' => "https://steamcommunity.com/sharedfiles/filedetails/?id={$id}",
            'error' => $error,
        ];
    }

    private function cacheKey(string $id): string
    {
        return 'conan-settings-editor.workshop.'.$id;
    }
}
