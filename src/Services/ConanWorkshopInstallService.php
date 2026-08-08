<?php

namespace Dtektion\ConanSettingsEditor\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Queues Workshop install jobs for the host/sidecar worker.
 *
 * Jobs live under the plugin storage tree so both the panel (uid 82) and the
 * host worker (bind-mounted) can exchange status without giving the panel
 * docker.sock access.
 */
class ConanWorkshopInstallService
{
    public const APP_ID = 440900;

    public function jobsRoot(): string
    {
        $configured = config('conan-settings-editor.jobs_path');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return plugin_path('conan-settings-editor', 'storage/jobs');
    }

    /**
     * @param  list<string|int>  $workshopIds
     * @return array{job_id: string, path: string, status: string}
     */
    public function enqueue(mixed $server, array $workshopIds): array
    {
        $uuid = (string) data_get($server, 'uuid');
        if ($uuid === '' || ! preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            throw new RuntimeException('Server UUID missing; cannot queue Workshop install.');
        }

        $ids = app(ConanModListService::class)->normalizeIdList($workshopIds);
        if ($ids === []) {
            throw new RuntimeException('No valid Workshop IDs to install.');
        }

        // Only one active job per server
        $active = $this->findActiveJobForServer($uuid);
        if ($active !== null) {
            throw new RuntimeException('An install job is already '.$active['status'].' for this server ('.$active['job_id'].').');
        }

        $this->ensureJobDirs();

        $jobId = 'job-'.gmdate('Ymd-His').'-'.Str::lower(Str::random(6));
        $job = [
            'job_id' => $jobId,
            'server_uuid' => $uuid,
            'server_id' => data_get($server, 'id'),
            'server_name' => data_get($server, 'name'),
            'workshop_ids' => $ids,
            'status' => 'pending',
            'created_at' => gmdate('c'),
            'created_by' => data_get(user(), 'username') ?? data_get(user(), 'email') ?? 'panel',
            'app_id' => self::APP_ID,
        ];

        $path = $this->jobsRoot().'/pending/'.$jobId.'.json';
        $this->writeJson($path, $job);

        return [
            'job_id' => $jobId,
            'path' => $path,
            'status' => 'pending',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getJob(string $jobId): ?array
    {
        foreach (['running', 'pending', 'done', 'failed'] as $bucket) {
            $path = $this->jobsRoot().'/'.$bucket.'/'.$jobId.'.json';
            if (is_readable($path)) {
                $job = $this->readJson($path);
                if (is_array($job)) {
                    $job['bucket'] = $bucket;
                    $progressPath = $this->jobsRoot().'/'.$bucket.'/'.$jobId.'.progress.json';
                    if (! is_readable($progressPath) && $bucket === 'running') {
                        $progressPath = $this->jobsRoot().'/running/'.$jobId.'.progress.json';
                    }
                    if (is_readable($progressPath)) {
                        $job['progress'] = $this->readJson($progressPath);
                    }

                    return $job;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestJobForServer(string $serverUuid): ?array
    {
        $candidates = [];
        foreach (['running', 'pending', 'done', 'failed'] as $bucket) {
            $dir = $this->jobsRoot().'/'.$bucket;
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir.'/*.json') ?: [] as $path) {
                if (str_ends_with($path, '.progress.json')) {
                    continue;
                }
                $job = $this->readJson($path);
                if (! is_array($job)) {
                    continue;
                }
                if ((string) ($job['server_uuid'] ?? '') !== $serverUuid) {
                    continue;
                }
                $job['bucket'] = $bucket;
                $job['_path'] = $path;
                $candidates[] = $job;
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });
        $job = $candidates[0];
        $jobId = (string) ($job['job_id'] ?? pathinfo((string) $job['_path'], PATHINFO_FILENAME));
        $bucket = (string) $job['bucket'];
        $progressPath = $this->jobsRoot().'/'.$bucket.'/'.$jobId.'.progress.json';
        if (is_readable($progressPath)) {
            $job['progress'] = $this->readJson($progressPath);
        }
        unset($job['_path']);

        return $job;
    }

    public function workerSeemsAlive(): bool
    {
        // Heuristic: a running job with recent progress, or marker file from worker.
        $marker = $this->jobsRoot().'/worker.heartbeat';
        if (is_readable($marker)) {
            $mtime = filemtime($marker) ?: 0;
            if (time() - $mtime < 30) {
                return true;
            }
        }
        $running = glob($this->jobsRoot().'/running/*.json') ?: [];
        foreach ($running as $path) {
            if (str_ends_with($path, '.progress.json')) {
                continue;
            }
            $progress = $this->jobsRoot().'/running/'.pathinfo($path, PATHINFO_FILENAME).'.progress.json';
            if (is_readable($progress) && (time() - (filemtime($progress) ?: 0) < 120)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveJobForServer(string $uuid): ?array
    {
        foreach (['pending', 'running'] as $bucket) {
            $dir = $this->jobsRoot().'/'.$bucket;
            foreach (glob($dir.'/*.json') ?: [] as $path) {
                if (str_ends_with($path, '.progress.json')) {
                    continue;
                }
                $job = $this->readJson($path);
                if (! is_array($job)) {
                    continue;
                }
                if ((string) ($job['server_uuid'] ?? '') === $uuid) {
                    return [
                        'job_id' => (string) ($job['job_id'] ?? pathinfo($path, PATHINFO_FILENAME)),
                        'status' => (string) ($job['status'] ?? $bucket),
                    ];
                }
            }
        }

        return null;
    }

    private function ensureJobDirs(): void
    {
        foreach (['pending', 'running', 'done', 'failed', 'logs'] as $dir) {
            $path = $this->jobsRoot().'/'.$dir;
            if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
                throw new RuntimeException("Cannot create job directory: {$path}");
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $tmp = $path.'.tmp-'.getmypid();
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException("Failed writing job file: {$path}");
        }
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed finalizing job file: {$path}");
        }
    }
}
