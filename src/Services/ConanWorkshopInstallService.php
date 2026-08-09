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
 *
 * Queue model (R1):
 * - Multiple jobs per server are allowed (FIFO via worker oldest-first).
 * - New IDs merge into an existing *pending* job for the same server.
 * - If a job is already *running*, a new pending job is created instead of failing.
 * - Worker processes one job at a time globally (sequential SteamCMD).
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
     * Enqueue Workshop downloads. Never rejects because another job is active —
     * merges into pending or appends a new pending job behind the running one.
     *
     * @param  list<string|int>  $workshopIds  Full desired load order (always preserved on disk).
     * @param  list<string|int>|null  $downloadIds  Subset to SteamCMD this run; null = all workshopIds.
     * @return array{
     *   job_id: string,
     *   path: string,
     *   status: string,
     *   workshop_ids: list<string>,
     *   download_ids: list<string>,
     *   load_order_ids: list<string>,
     *   merged: bool,
     *   already_queued: bool,
     *   queue_depth: int,
     *   running_job_id: ?string,
     *   pending_count: int
     * }
     */
    public function enqueue(mixed $server, array $workshopIds, ?array $downloadIds = null): array
    {
        $uuid = (string) data_get($server, 'uuid');
        if ($uuid === '' || ! preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            throw new RuntimeException('Server UUID missing; cannot queue Workshop install.');
        }

        $modList = app(ConanModListService::class);
        $loadOrder = $modList->normalizeIdList($workshopIds);
        if ($loadOrder === []) {
            throw new RuntimeException('No valid Workshop IDs in load order.');
        }

        $toDownload = $downloadIds === null
            ? $loadOrder
            : $modList->normalizeIdList($downloadIds);
        // Only download ids that are part of the load order (or append unknowns into order).
        foreach ($toDownload as $id) {
            if (! in_array($id, $loadOrder, true)) {
                $loadOrder[] = $id;
            }
        }
        if ($toDownload === []) {
            throw new RuntimeException('No Workshop IDs to download (all paks present?).');
        }

        $this->ensureJobDirs();

        $pending = $this->findPendingJobForServer($uuid);

        // Merge into existing pending job (keeps one SteamCMD batch when possible).
        if ($pending !== null) {
            $path = (string) $pending['_path'];
            $existingOrder = array_values(array_map(
                'strval',
                $pending['load_order_ids'] ?? $pending['workshop_ids'] ?? []
            ));
            $existingDownload = array_values(array_map(
                'strval',
                $pending['download_ids'] ?? $pending['workshop_ids'] ?? []
            ));

            $mergedOrder = $existingOrder;
            foreach ($loadOrder as $id) {
                if (! in_array($id, $mergedOrder, true)) {
                    $mergedOrder[] = $id;
                }
            }

            $mergedDownload = $existingDownload;
            $added = [];
            foreach ($toDownload as $id) {
                if (! in_array($id, $mergedDownload, true)) {
                    $mergedDownload[] = $id;
                    $added[] = $id;
                }
                if (! in_array($id, $mergedOrder, true)) {
                    $mergedOrder[] = $id;
                }
            }

            $alreadyQueued = $added === [];
            if (! $alreadyQueued || $mergedOrder !== $existingOrder) {
                $pending['workshop_ids'] = $mergedOrder; // full order (worker + UI)
                $pending['load_order_ids'] = $mergedOrder;
                $pending['download_ids'] = $mergedDownload;
                $plat = app(ConanConfigPlatformService::class)->resolve($server);
                $pending['config_platform'] = (string) $plat['platform'];
                $pending['os_hint'] = (string) $plat['os_hint'];
                $pending['updated_at'] = gmdate('c');
                $pending['last_enqueued_ids'] = $added;
                $pending['last_enqueued_at'] = gmdate('c');
                unset($pending['_path'], $pending['bucket']);
                $this->writeJson($path, $pending);
            }

            $summary = $this->queueSummaryForServer($uuid);

            return [
                'job_id' => (string) ($pending['job_id'] ?? pathinfo($path, PATHINFO_FILENAME)),
                'path' => $path,
                'status' => 'pending',
                'workshop_ids' => $mergedOrder,
                'download_ids' => $mergedDownload,
                'load_order_ids' => $mergedOrder,
                'merged' => ! $alreadyQueued,
                'already_queued' => $alreadyQueued,
                'queue_depth' => (int) $summary['queue_depth'],
                'running_job_id' => $summary['running_job_id'],
                'pending_count' => (int) $summary['pending_count'],
            ];
        }

        // No pending job: create a new one (may sit behind a running job).
        $jobId = 'job-'.gmdate('Ymd-His').'-'.Str::lower(Str::random(6));
        $platform = app(ConanConfigPlatformService::class)->resolve($server);
        $job = [
            'job_id' => $jobId,
            'server_uuid' => $uuid,
            'server_id' => data_get($server, 'id'),
            'server_name' => data_get($server, 'name'),
            // workshop_ids = full load order (backward compatible field name for workers/UI)
            'workshop_ids' => $loadOrder,
            'load_order_ids' => $loadOrder,
            'download_ids' => $toDownload,
            'config_platform' => (string) $platform['platform'],
            'os_hint' => (string) $platform['os_hint'],
            'status' => 'pending',
            'created_at' => gmdate('c'),
            'created_by' => data_get(user(), 'username') ?? data_get(user(), 'email') ?? 'panel',
            'app_id' => self::APP_ID,
        ];

        $path = $this->jobsRoot().'/pending/'.$jobId.'.json';
        $this->writeJson($path, $job);

        $summary = $this->queueSummaryForServer($uuid);

        return [
            'job_id' => $jobId,
            'path' => $path,
            'status' => 'pending',
            'workshop_ids' => $loadOrder,
            'download_ids' => $toDownload,
            'load_order_ids' => $loadOrder,
            'merged' => false,
            'already_queued' => false,
            'queue_depth' => (int) $summary['queue_depth'],
            'running_job_id' => $summary['running_job_id'],
            'pending_count' => (int) $summary['pending_count'],
        ];
    }

    /**
     * Active (pending + running) jobs for a server, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveJobsForServer(string $serverUuid): array
    {
        $jobs = [];
        foreach (['pending', 'running'] as $bucket) {
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
                $jobId = (string) ($job['job_id'] ?? pathinfo($path, PATHINFO_FILENAME));
                $progressPath = $this->jobsRoot().'/'.$bucket.'/'.$jobId.'.progress.json';
                if (is_readable($progressPath)) {
                    $job['progress'] = $this->readJson($progressPath);
                }
                $jobs[] = $job;
            }
        }

        usort($jobs, static function (array $a, array $b): int {
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        });

        return $jobs;
    }

    /**
     * Prefer running job, else oldest pending, else latest terminal job.
     *
     * @return array<string, mixed>|null
     */
    public function preferredJobForServer(string $serverUuid): ?array
    {
        $active = $this->listActiveJobsForServer($serverUuid);
        foreach ($active as $job) {
            if (($job['bucket'] ?? '') === 'running') {
                return $job;
            }
        }
        if ($active !== []) {
            return $active[0];
        }

        return $this->latestTerminalJobForServer($serverUuid);
    }

    /**
     * @return array{
     *   queue_depth: int,
     *   pending_count: int,
     *   running_count: int,
     *   running_job_id: ?string,
     *   pending_job_ids: list<string>,
     *   active_ids: list<string>,
     *   summary: string
     * }
     */
    public function queueSummaryForServer(string $serverUuid): array
    {
        $active = $this->listActiveJobsForServer($serverUuid);
        $pendingIds = [];
        $runningId = null;
        $runningCount = 0;
        $allWorkshop = [];

        foreach ($active as $job) {
            $jid = (string) ($job['job_id'] ?? '');
            $bucket = (string) ($job['bucket'] ?? '');
            if ($bucket === 'running') {
                $runningCount++;
                $runningId = $jid !== '' ? $jid : $runningId;
            } elseif ($bucket === 'pending' && $jid !== '') {
                $pendingIds[] = $jid;
            }
            foreach ($job['workshop_ids'] ?? [] as $wid) {
                $wid = (string) $wid;
                if ($wid !== '' && ! in_array($wid, $allWorkshop, true)) {
                    $allWorkshop[] = $wid;
                }
            }
        }

        $depth = count($active);
        $parts = [];
        if ($runningId !== null) {
            $parts[] = 'active '.$runningId;
        }
        if ($pendingIds !== []) {
            $parts[] = count($pendingIds).' pending';
        }
        if ($allWorkshop !== []) {
            $parts[] = count($allWorkshop).' workshop id(s) in queue';
        }

        return [
            'queue_depth' => $depth,
            'pending_count' => count($pendingIds),
            'running_count' => $runningCount,
            'running_job_id' => $runningId,
            'pending_job_ids' => $pendingIds,
            'active_ids' => $allWorkshop,
            'summary' => $depth === 0
                ? 'Queue empty'
                : ('Queue depth '.$depth.($parts !== [] ? ' — '.implode('; ', $parts) : '')),
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
     * Latest job for server (active preferred, else newest terminal).
     *
     * @return array<string, mixed>|null
     */
    public function latestJobForServer(string $serverUuid): ?array
    {
        $preferred = $this->preferredJobForServer($serverUuid);
        if ($preferred !== null) {
            unset($preferred['_path']);

            return $preferred;
        }

        return null;
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
     * @return array<string, mixed>|null job with _path and bucket
     */
    private function findPendingJobForServer(string $uuid): ?array
    {
        $dir = $this->jobsRoot().'/pending';
        if (! is_dir($dir)) {
            return null;
        }

        $candidates = [];
        foreach (glob($dir.'/*.json') ?: [] as $path) {
            if (str_ends_with($path, '.progress.json')) {
                continue;
            }
            $job = $this->readJson($path);
            if (! is_array($job)) {
                continue;
            }
            if ((string) ($job['server_uuid'] ?? '') !== $uuid) {
                continue;
            }
            $job['bucket'] = 'pending';
            $job['_path'] = $path;
            $candidates[] = $job;
        }

        if ($candidates === []) {
            return null;
        }

        // Oldest pending first (same as worker claim order).
        usort($candidates, static function (array $a, array $b): int {
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        });

        return $candidates[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestTerminalJobForServer(string $serverUuid): ?array
    {
        $candidates = [];
        foreach (['done', 'failed'] as $bucket) {
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
