<?php

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Models\Server;

class LicensePlayerDataService
{
    public function __construct(
        private DynamicDatabaseConnection $dynamicDatabaseConnection,
    ) {
    }

    /**
     * @param array<int, string> $playerNames
     * @return array{players: array<string, array{licensed: ?bool}>, warnings: array<int, string>}
     */
    public function load(Server $server, array $playerNames): array
    {
        $warnings = [];
        $normalizedNames = array_values(array_unique(array_filter(array_map(function ($name) {
            return is_string($name) ? trim($name) : '';
        }, $playerNames), fn ($name) => $name !== '')));

        if ($normalizedNames === []) {
            return [
                'players' => [],
                'warnings' => [],
            ];
        }

        $table = trim((string) config('minecraftplayermanager.license.table', ''));
        if ($table === '') {
            return [
                'players' => [],
                'warnings' => [],
            ];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [
                'players' => [],
                'warnings' => ['License table name is invalid. Database license flags are ignored.'],
            ];
        }

        $userColumn = trim((string) config('minecraftplayermanager.license.username_column', 'username'));
        $licensedColumn = trim((string) config('minecraftplayermanager.license.licensed_column', 'licensed'));
        if (!preg_match('/^[A-Za-z0-9_]+$/', $userColumn) || !preg_match('/^[A-Za-z0-9_]+$/', $licensedColumn)) {
            return [
                'players' => [],
                'warnings' => ['License column names are invalid. Database license flags are ignored.'],
            ];
        }

        $databases = $server->databases()->with('host')->get();
        if ($databases->isEmpty()) {
            return [
                'players' => [],
                'warnings' => ['No server databases are configured. License table is unavailable.'],
            ];
        }

        $tableFound = false;
        $connectionFailed = false;

        foreach ($databases as $database) {
            $connection = sprintf('minecraftplayermanager_license_%d_%d', $server->id, $database->id);

            try {
                $this->dynamicDatabaseConnection->set($connection, $database->host, $database->database);
                DB::purge($connection);

                try {
                    DB::connection($connection)->table($table)->select([$userColumn])->limit(1)->get();
                    $tableFound = true;
                } catch (QueryException $exception) {
                    if ($this->isMissingTableException($exception)) {
                        continue;
                    }

                    throw $exception;
                }

                $rows = DB::connection($connection)
                    ->table($table)
                    ->selectRaw('`' . $userColumn . '` as username_key, `' . $licensedColumn . '` as licensed_raw')
                    ->whereIn($userColumn, $normalizedNames)
                    ->get();

                $players = [];
                foreach ($rows as $row) {
                    $username = strtolower(trim((string) ($row->username_key ?? '')));
                    if ($username === '') {
                        continue;
                    }

                    $players[$username] = [
                        'licensed' => $this->normalizeBoolean($row->licensed_raw ?? null),
                    ];
                }

                return [
                    'players' => $players,
                    'warnings' => $warnings,
                ];
            } catch (\Throwable $exception) {
                $connectionFailed = true;
                $warnings[] = sprintf(
                    'Failed to read license data from database "%s".',
                    $database->database
                );
            } finally {
                DB::disconnect($connection);
                DB::purge($connection);
            }
        }

        if (!$tableFound && !$connectionFailed) {
            $warnings[] = sprintf(
                'License table "%s" was not found in server databases.',
                $table
            );
        }

        return [
            'players' => [],
            'warnings' => $warnings,
        ];
    }

    private function isMissingTableException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($sqlState === '42S02' || $driverCode === 1146) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'doesn\'t exist');
    }

    private function normalizeBoolean(mixed $raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (int) $raw !== 0;
        }

        $normalized = strtolower(trim((string) $raw));
        if ($normalized === 'true' || $normalized === 'yes') {
            return true;
        }
        if ($normalized === 'false' || $normalized === 'no') {
            return false;
        }

        return null;
    }
}
