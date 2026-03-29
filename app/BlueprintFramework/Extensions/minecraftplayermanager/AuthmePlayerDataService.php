<?php

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;
use Illuminate\Database\QueryException;
use Pterodactyl\Extensions\DynamicDatabaseConnection;

class AuthmePlayerDataService
{
    public function __construct(
        private DynamicDatabaseConnection $dynamicDatabaseConnection,
    ) {
    }

    /**
     * @param array<int, string> $playerNames
     * @return array{players: array<string, array{first_seen_at: ?string, last_login_at: ?string, has_session: ?bool, reg_ip: ?string, ip: ?string, world: ?string}>, warnings: array<int, string>}
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

        $table = trim((string) config('minecraftplayermanager.authme.table', 'authme'));
        if ($table === '') {
            return [
                'players' => [],
                'warnings' => ['AuthMe table is not configured. First login date is unavailable.'],
            ];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [
                'players' => [],
                'warnings' => ['AuthMe table name is invalid. First login date is unavailable.'],
            ];
        }

        $databases = $server->databases()->with('host')->get();
        if ($databases->isEmpty()) {
            return [
                'players' => [],
                'warnings' => ['No server databases are configured. AuthMe data is unavailable.'],
            ];
        }

        $tableFound = false;
        $connectionFailed = false;

        foreach ($databases as $database) {
            $connection = sprintf('minecraftplayermanager_authme_%d_%d', $server->id, $database->id);

            try {
                $this->dynamicDatabaseConnection->set($connection, $database->host, $database->database);
                DB::purge($connection);

                try {
                    DB::connection($connection)->table($table)->select(['username'])->limit(1)->get();
                    $tableFound = true;
                } catch (QueryException $exception) {
                    if ($this->isMissingTableException($exception)) {
                        continue;
                    }

                    throw $exception;
                }

                $rows = DB::connection($connection)
                    ->table($table)
                    ->select([
                        'username',
                        'regdate',
                        'lastlogin',
                        'regip',
                        'ip',
                        'world',
                        DB::raw('`hasSession` as has_session'),
                    ])
                    ->whereIn('username', $normalizedNames)
                    ->get();

                $players = [];
                foreach ($rows as $row) {
                    $username = strtolower(trim((string) ($row->username ?? '')));
                    if ($username === '') {
                        continue;
                    }

                    $players[$username] = [
                        'first_seen_at' => $this->normalizeAuthmeTimestamp($row->regdate ?? null),
                        'last_login_at' => $this->normalizeAuthmeTimestamp($row->lastlogin ?? null),
                        'has_session' => $this->normalizeBoolean($row->has_session ?? null),
                        'reg_ip' => $this->normalizeString($row->regip ?? null),
                        'ip' => $this->normalizeString($row->ip ?? null),
                        'world' => $this->normalizeString($row->world ?? null),
                    ];
                }

                return [
                    'players' => $players,
                    'warnings' => $warnings,
                ];
            } catch (\Throwable $exception) {
                $connectionFailed = true;
                $warnings[] = sprintf(
                    'Failed to read AuthMe data from database "%s".',
                    $database->database
                );
            } finally {
                DB::disconnect($connection);
                DB::purge($connection);
            }
        }

        if (!$tableFound && !$connectionFailed) {
            $warnings[] = sprintf(
                'AuthMe table "%s" was not found in server databases. First login date is unavailable.',
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

    private function normalizeAuthmeTimestamp(mixed $raw): ?string
    {
        if (!is_numeric($raw)) {
            return null;
        }

        $timestamp = (int) $raw;
        if ($timestamp <= 0) {
            return null;
        }

        if ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        try {
            return (new \DateTimeImmutable("@{$timestamp}"))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable $exception) {
            return null;
        }
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

    private function normalizeString(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }

        $value = trim((string) $raw);
        return $value === '' ? null : $value;
    }
}
