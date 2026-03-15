<?php

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Illuminate\Support\Collection;

class PlayerManagerUserCache
{
    public function __construct(
        private DaemonFileRepository $fileRepository,
        private PlayerManagerUtilities $utils,
    ) {}

    public function collect(Server $server): Collection
    {
        return Cache::remember("minecraftserver:username-cache:{$server->id}", 30, function () use ($server) {
            $collection = Collection::make();

            try {
                $cache = $this->fileRepository->setServer($server)->getContent('/usercache.json');
                $data = json_decode($cache, true);

                foreach ($data as $player) {
                    $collection->put(str_replace('-', '', $player['uuid']), $player['name']);
                    $collection->put($player['name'], str_replace('-', '', $player['uuid']));
                }
            } catch (\Throwable $e) {
            }

            return $collection;
        });
    }

    public function save(Server $server, Collection $cache): void
    {
        $data = [];

        foreach ($cache as $uuid => $name) {
            if (strlen($uuid) !== 32 && strlen($uuid) !== 36) {
                continue;
            }

            $data[] = [
                'uuid' => $this->utils->formatUuid($uuid),
                'name' => $name,
            ];
        }

        $this->fileRepository->setServer($server)->putContent('/usercache.json', json_encode($data, JSON_PRETTY_PRINT));
        Cache::put("minecraftserver:username-cache:{$server->id}", $cache, 30);
    }

    public function lookupUuid(Server $server, string $uuid): array|null
    {
        $name = config('app.name', 'Pterodactyl');
        $uuid = str_replace('-', '', $uuid);
        $cache = $this->collect($server);

        $cached = $cache->get($uuid);
        if ($cached) {
            return [
                'uuid' => $this->utils->formatUuid($uuid),
                'name' => $cached,
            ];
        }

        if (str_starts_with($uuid, '0000000000000000')) {
            $data = Cache::remember("minecraftplayer:floodgate:$uuid", 1000, function () use ($server, $name, $uuid, $cache) {
                $xuidHex = substr($uuid, 19);
                $xuid = base_convert($xuidHex, 16, 10);

                try {
                    $req = Http::withUserAgent("Player Manager by 0x7d8 @ $name")
                        ->timeout(5)
                        ->retry(2, 100, throw: true)
                        ->get("https://api.geysermc.org/v2/xbox/gamertag/$xuid");

                    $user = json_decode($req->getBody()->getContents(), true);

                    $floodgatePrefix = $this->utils->getFloodgatePrefix($server);

                    $cache->put($uuid, $floodgatePrefix . $user['gamertag']);
                    $cache->put($floodgatePrefix . $user['gamertag'], $uuid);

                    $this->save($server, $cache);

                    return [
                        'uuid' => $this->utils->formatUuid($uuid),
                        'name' => $floodgatePrefix . $user['gamertag'],
                    ];
                } catch (\Throwable $e) {
                    return null;
                }
            });

            if (!is_null($data)) {
                return $data;
            }
        }

        $data = Cache::remember("minecraftplayer:$uuid", 1000, function () use ($server, $name, $uuid, $cache) {
            try {
                $req = Http::withUserAgent("Player Manager by 0x7d8 @ $name")
                    ->timeout(5)
                    ->retry(2, 100, throw: true)
                    ->get("https://sessionserver.mojang.com/session/minecraft/profile/$uuid");

                $user = json_decode($req->getBody()->getContents(), true);

                $cache->put(str_replace('-', '', $user['id']), $user['name']);
                $cache->put($user['name'], str_replace('-', '', $user['id']));

                $this->save($server, $cache);

                return $user;
            } catch (\Throwable $e) {
                return null;
            }
        });

        if (is_null($data)) {
            return null;
        }

        return [
            'uuid' => $this->utils->formatUuid($data['id']),
            'name' => $data['name'],
        ];
    }

    public function lookupName(Server $server, string $name): array|null
    {
        $app = config('app.name', 'Pterodactyl');
        $floodgatePrefix = $this->utils->getFloodgatePrefix($server);
        $cache = $this->collect($server);

        $cached = $cache->get($name);
        if ($cached) {
            return [
                'uuid' => $this->utils->formatUuid($cached),
                'name' => $name,
            ];
        }

        if ($floodgatePrefix && str_starts_with($name, $floodgatePrefix)) {
            $data = Cache::remember("minecraftplayer:floodgate:$name", 1000, function () use ($server, $app, $name, $floodgatePrefix, $cache) {
                $name = urlencode($name);
                $floodgatePrefix = urlencode($floodgatePrefix);

                try {
                    $req = Http::withUserAgent("Player Manager by 0x7d8 @ $app")
                        ->timeout(5)
                        ->retry(2, 100, throw: true)
                        ->get("https://api.geysermc.org/v2/utils/uuid/bedrock_or_java/$name?prefix=$floodgatePrefix");

                    $user = json_decode($req->getBody()->getContents(), true);

                    $cache->put(str_replace('-', '', $user['uuid']), $user['name']);
                    $cache->put($user['name'], str_replace('-', '', $user['uuid']));

                    $this->save($server, $cache);

                    return [
                        'uuid' => $this->utils->formatUuid($user['uuid']),
                        'name' => $user['name'],
                    ];
                } catch (\Throwable $e) {
                    return null;
                }
            });
        }

        if ($this->utils->isOfflineMode($server)) {
            $uuid = $this->utils->formatUuid(md5("OfflinePlayer:$name"));

            $cache->put(str_replace('-', '', $uuid), $name);
            $cache->put($name, str_replace('-', '', $uuid));

            $this->save($server, $cache);

            return [
                'uuid' => $uuid,
                'name' => $name,
            ];
        }

        $data = Cache::remember("minecraftplayer:$name", 1000, function () use ($server, $app, $name, $cache) {
            try {
                $req = Http::withUserAgent("Player Manager by 0x7d8 @ $app")
                    ->timeout(5)
                    ->retry(2, 100, throw: true)
                    ->get("https://api.mojang.com/users/profiles/minecraft/$name");

                $user = json_decode($req->getBody()->getContents(), true);

                $cache->put(str_replace('-', '', $user['id']), $user['name']);
                $cache->put($user['name'], str_replace('-', '', $user['id']));

                $this->save($server, $cache);

                return $user;
            } catch (\Throwable $e) {
                return null;
            }
        });

        if (is_null($data)) {
            return null;
        }

        return [
            'uuid' => $this->utils->formatUuid($data['id']),
            'name' => $data['name'],
        ];
    }
}
