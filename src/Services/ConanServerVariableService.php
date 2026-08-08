<?php

namespace Dtektion\ConanSettingsEditor\Services;

use App\Models\EggVariable;
use App\Models\ServerVariable;
use RuntimeException;
use Throwable;

/**
 * Read/write Pelican egg environment variables for a server (e.g. SRV_NAME).
 */
class ConanServerVariableService
{
    public function get(mixed $server, string $envVariable): ?string
    {
        $row = $this->findServerVariable($server, $envVariable);
        if ($row === null) {
            return null;
        }

        return (string) ($row->variable_value ?? '');
    }

    public function set(mixed $server, string $envVariable, string $value): void
    {
        $row = $this->findServerVariable($server, $envVariable);
        if ($row === null) {
            throw new RuntimeException("Egg variable {$envVariable} is not defined on this server.");
        }
        $row->variable_value = $value;
        $row->save();
    }

    public function has(mixed $server, string $envVariable): bool
    {
        return $this->findServerVariable($server, $envVariable) !== null;
    }

    private function findServerVariable(mixed $server, string $envVariable): ?ServerVariable
    {
        try {
            $serverId = data_get($server, 'id');
            $eggId = data_get($server, 'egg_id') ?? data_get($server, 'egg.id');
            if ($serverId === null) {
                return null;
            }

            $eggVar = EggVariable::query()
                ->when($eggId !== null, fn ($q) => $q->where('egg_id', $eggId))
                ->where('env_variable', $envVariable)
                ->first();

            if ($eggVar === null) {
                // fallback: match by env across all egg vars linked to this server
                $candidates = EggVariable::query()->where('env_variable', $envVariable)->pluck('id');
                if ($candidates->isEmpty()) {
                    return null;
                }

                return ServerVariable::query()
                    ->where('server_id', $serverId)
                    ->whereIn('variable_id', $candidates)
                    ->first();
            }

            return ServerVariable::query()
                ->where('server_id', $serverId)
                ->where('variable_id', $eggVar->id)
                ->first();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
