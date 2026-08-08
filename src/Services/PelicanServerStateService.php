<?php

namespace Dtektion\ConanSettingsEditor\Services;

use Throwable;

class PelicanServerStateService
{
    /** @var array<int|string, mixed> */
    private array $statusCache = [];

    public function clearStatusCache(): void
    {
        $this->statusCache = [];
    }

    public function isSafeToEdit(mixed $server): bool
    {
        $status = $this->resolveStatus($server);

        return $status !== null && $this->statusIsOffline($status);
    }

    public function getStateLabel(mixed $server): string
    {
        $value = $this->statusValue($this->resolveStatus($server));

        return $value === null ? 'Unknown' : ucfirst($value);
    }

    public function getStatusMessage(mixed $server): string
    {
        $status = $this->resolveStatus($server);

        if ($status === null) {
            return 'Editing is disabled because the current server state could not be confirmed.';
        }

        if ($this->isSafeToEdit($server)) {
            return 'Server is stopped. Conan settings can be edited safely. Start the server after saving for changes to apply.';
        }

        $value = $this->statusValue($status);
        if ($value === null || $value === 'missing') {
            return 'Editing is disabled because the current server state could not be confirmed.';
        }

        return 'Editing is disabled while the server is not stopped. Stop the server first so Conan does not overwrite your INI on shutdown.';
    }

    /** @return array<string, mixed> */
    public function getStateDiagnostics(mixed $server): array
    {
        $status = $this->resolveStatus($server);

        return [
            'status' => $this->statusValue($status),
            'is_offline' => $status !== null && $this->statusIsOffline($status),
            'server.id' => data_get($server, 'id'),
            'server.uuid' => data_get($server, 'uuid'),
        ];
    }

    private function resolveStatus(mixed $server): mixed
    {
        $key = data_get($server, 'id') ?? data_get($server, 'uuid') ?? spl_object_id((object) $server);
        if (array_key_exists($key, $this->statusCache)) {
            return $this->statusCache[$key];
        }
        try {
            if (is_object($server) && method_exists($server, 'retrieveStatus')) {
                return $this->statusCache[$key] = $server->retrieveStatus();
            }
        } catch (Throwable) {
        }

        return $this->statusCache[$key] = null;
    }

    private function statusIsOffline(mixed $status): bool
    {
        return in_array($this->statusValue($status), ['offline', 'exited'], true);
    }

    private function statusValue(mixed $status): ?string
    {
        if ($status === null) {
            return null;
        }
        if (is_string($status)) {
            return strtolower(trim($status)) ?: null;
        }
        $value = data_get($status, 'value');
        if (is_string($value) && $value !== '') {
            return strtolower($value);
        }
        if (is_object($status) && property_exists($status, 'name') && is_string($status->name)) {
            return strtolower($status->name);
        }

        return null;
    }
}
