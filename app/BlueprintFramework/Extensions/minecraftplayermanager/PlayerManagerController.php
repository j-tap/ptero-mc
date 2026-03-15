<?php

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager;

use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonCommandRepository;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerIpRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerGetRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerBanRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerKickRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Dependencies\Status\MinecraftPing;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerBanIpRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Dependencies\Status\MinecraftQuery;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Dependencies\Rickselby\Nbt\Service;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerPlayerRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerWhisperRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Dependencies\Rickselby\Nbt\DataHandler;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerPlayerNamedRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerSetWhitelistRequest;

class PlayerManagerController extends ClientApiController
{
    public function __construct(
        private DaemonFileRepository $fileRepository,
        private DaemonCommandRepository $commandRepository,
        private PlayerManagerUtilities $utils,
        private PlayerManagerUserCache $cache,
    ) {
        parent::__construct();
    }

    private function queryApi(Server $server): array
    {
        return Cache::remember("minecraftserver:query:{$server->id}", 5, function () use ($server) {
            $useAlias = $server->allocation->alias !== $server->allocation->ip && (filter_var($server->allocation->alias, FILTER_VALIDATE_IP) || checkdnsrr($server->allocation->alias . '.', 'A') || checkdnsrr($server->allocation->alias . '.', 'AAAA'));

            try {
                if (!$this->utils->isQueryEnabled($server)) {
                    throw new \Exception('Query is not enabled');
                }

                $query = new MinecraftQuery();

                try {
                    if ($useAlias) {
                        $query->Connect($server->allocation->alias, $server->allocation->port, 2, false);
                    } else {
                        $query->Connect($server->allocation->ip, $server->allocation->port, 2, false);
                    }

                    $data = $query->GetInfo();
                } catch (\Throwable $e) {
                    $data = false;
                }

                if (!$data && $useAlias) {
                    $query = new MinecraftQuery();

                    $query->Connect($server->allocation->ip, $server->allocation->port, 2, false);
                    $data = $query->GetInfo();
                }

                if (!$data) throw new \Exception('Failed to query server');

                $players = [];
                $rawPlayers = $query->GetPlayers();
                if ($rawPlayers) foreach ($rawPlayers as $player) {
                    $userData = $this->cache->lookupName($server, $player);

                    if ($userData) {
                        $uuid = $userData['uuid'];
                    }

                    if (!$uuid) {
                        continue;
                    }

                    $players[] = [
                        'id' => $uuid,
                        'name' => $player,
                    ];
                }

                return [
                    'players' => [
                        'online' => $data['Players'],
                        'max' => $data['MaxPlayers'],
                        'list' => $players,
                    ],
                ];
            } catch (\Throwable $e) {
                if ($useAlias) {
                    $query = new MinecraftPing($server->allocation->alias, $server->allocation->port, 2, false);
                } else {
                    $query = new MinecraftPing($server->allocation->ip, $server->allocation->port, 2, false);
                }

                try {
                    $query->Connect();
                    $data = $query->Query();
                } catch (\Throwable $e) {
                    $data = false;
                }

                if (!$data && $useAlias) {
                    $query = new MinecraftPing($server->allocation->ip, $server->allocation->port, 2, false);

                    $query->Connect();
                    $data = $query->Query();
                }

                if (!$data) throw new \Exception('Failed to query server');

                return [
                    'players' => [
                        'online' => $data['players']['online'],
                        'max' => $data['players']['max'],
                        'list' => $data['players']['sample'] ?? [],
                    ],
                ];
            }
        });
    }

    private function sortList(array $list): array
    {
        usort($list, function ($a, $b) {
            return strcasecmp($a['name'] ?? $a['ip'], $b['name'] ?? $b['ip']);
        });

        return $list;
    }

    public function index(PlayerManagerGetRequest $request, Server $server): array
    {
        $properties = $this->utils->configs($server)['server.properties'];

        $opped = [];
        $whitelisted = [];
        $whitelistEnabled = $properties ? str_contains($properties, 'white-list=true') : false;
        $banned = [];
        $bannedIps = [];

        $ops = $this->utils->configs($server)['ops.json'];
        if ($ops) {
            $data = json_decode($ops, true);

            foreach ($data as $op) {
                $uuid = str_replace('-', '', $op['uuid']);

                $opped[] = [
                    'uuid' => $op['uuid'],
                    'name' => $op['name'],
                    'level' => $op['level'],
                    'bypassesPlayerLimit' => $op['bypassesPlayerLimit'],
                    'avatar' => "https://minotar.net/helm/$uuid/256.png",
                ];
            }
        }

        $whitelist = $this->utils->configs($server)['whitelist.json'];
        if ($whitelist) {
            $data = json_decode($whitelist, true);

            foreach ($data as $whitelist) {
                $uuid = str_replace('-', '', $whitelist['uuid']);

                $whitelisted[] = [
                    'uuid' => $whitelist['uuid'],
                    'name' => $whitelist['name'],
                    'avatar' => "https://minotar.net/helm/$uuid/256.png",
                ];
            }
        }

        $bannedPlayers = $this->utils->configs($server)['banned-players.json'];
        if ($bannedPlayers) {
            $data = json_decode($bannedPlayers, true);

            foreach ($data as $ban) {
                $uuid = str_replace('-', '', $ban['uuid']);

                $banned[] = [
                    'uuid' => $ban['uuid'],
                    'name' => $ban['name'],
                    'reason' => $ban['reason'],
                    'avatar' => "https://minotar.net/helm/$uuid/256.png",
                ];
            }
        }

        $bannedIpsRaw = $this->utils->configs($server)['banned-ips.json'];
        if ($bannedIpsRaw) {
            $data = json_decode($bannedIpsRaw, true);

            foreach ($data as $ban) {
                $bannedIps[] = [
                    'ip' => $ban['ip'],
                    'reason' => $ban['reason'],
                ];
            }
        }

        $start = microtime(true);

        try {
            $data = $this->queryApi($server);

            $players = [];
            foreach ($data['players']['list'] ?? [] as $player) {
                $uuid = str_replace('-', '', $player['id']);

                $players[] = [
                    'uuid' => $player['id'],
                    'name' => $player['name'],
                    'avatar' => "https://minotar.net/helm/$uuid/256.png",
                ];
            }

            return [
                'success' => true,
                'ping' => round((microtime(true) - $start) * 1000, 2),
                'online' => true,
                'online_mode' => !$this->utils->isOfflineMode($server),
                'is_proxy' => $this->utils->isProxy($server),
                'is_proxied' => $this->utils->isProxied($server),
                'opped' => $this->sortList($opped),
                'banned' => [
                    'players' => $this->sortList($banned),
                    'ips' => $this->sortList($bannedIps),
                ], 'whitelist' => [
                    'enabled' => $whitelistEnabled,
                    'list' => $this->sortList($whitelisted),
                ], 'players' => [
                    'online' => $data['players']['online'],
                    'max' => $data['players']['max'],
                    'list' => $this->sortList($players),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => true,
                'online' => false,
                'online_mode' => !$this->utils->isOfflineMode($server),
                'is_proxy' => $this->utils->isProxy($server),
                'is_proxied' => $this->utils->isProxied($server),
                'opped' => $this->sortList($opped),
                'banned' => [
                    'players' => $this->sortList($banned),
                    'ips' => $this->sortList($bannedIps),
                ], 'whitelist' => [
                    'enabled' => $whitelistEnabled,
                    'list' => $this->sortList($whitelisted),
                ],
            ];
        }
    }

    public function offline(PlayerManagerGetRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot list offline players on a proxy server',
            ], 400);
        }

        $properties = $this->utils->configs($server)['server.properties'];
        if (!$properties) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to find level name',
            ], 400);
        }

        $data = explode("\n", $properties);
        $levelName = null;

        foreach ($data as $line) {
            if (str_starts_with($line, 'level-name=')) {
                $levelName = explode('=', $line, 2)[1];
                break;
            }
        }

        if (!$levelName) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to find level name',
            ], 400);
        }

        try {
            $query = $this->queryApi($server);

            $exclude = array_map(function ($player) {
                return "{$this->utils->formatUuid($player['id'])}.dat";
            }, $query['players']['list'] ?? []);
        } catch (\Throwable $e) {
            $exclude = [];
        }

        $cache = $this->cache->collect($server);
        $playerData = $this->fileRepository->setServer($server)->getDirectory("$levelName/playerdata");
        $players = [];

        foreach ($playerData as $file) {
            if (!str_ends_with($file['name'], '.dat') || in_array($file['name'], $exclude)) {
                continue;
            }

            $uuid = str_replace('.dat', '', $file['name']);
            $name = $cache->get(str_replace('-', '', $uuid));

            if ($name) {
                $players[] = [
                    'uuid' => $uuid,
                    'name' => $name,
                    'avatar' => "https://minotar.net/helm/$uuid/256.png",
                ];
            }
        }

        return new JsonResponse([
            'success' => true,
            'players' => $this->sortList($players),
        ]);
    }

    private const MINESKIN_API = 'https://api.mineskin.org';
    private const MINOTAR_SKIN = 'https://minotar.net/skin';

    public function skin(PlayerManagerGetRequest $request)
    {
        $content = file_get_contents(resource_path('views/extensions/minecraftplayermanager/skin.html'));

        return response($content, 200, [
            'Content-Type' => 'text/html',
            'Cache-Control' => 'public, max-age=31536000',
            'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000),
            'Pragma' => 'cache',
        ]);
    }

    public function skinTexture(PlayerManagerGetRequest $request, Server $server): JsonResponse
    {
        $uuid = $request->query('uuid');
        if (!$uuid || strlen($uuid) < 32) {
            return new JsonResponse(['skinUrl' => null, 'capeUrl' => null], 400);
        }

        $uuid = $this->utils->formatUuid($uuid);
        $uuidParam = str_replace('-', '', $uuid);

        $skinUrl = self::MINOTAR_SKIN . '/' . $uuidParam;
        $capeUrl = null;

        $apiUrl = self::MINESKIN_API . '/get/uuid/' . $uuid;
        $headers = [
            'User-Agent: Pterodactyl-PlayerManager/1.0',
        ];
        $apiKey = env('MINESKIN_API_KEY');
        if ($apiKey) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($apiUrl, false, $ctx);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (isset($data['data']['texture']['url'])) {
                $skinUrl = $data['data']['texture']['url'];
            }
        }

        return new JsonResponse([
            'skinUrl' => $skinUrl,
            'capeUrl' => $capeUrl,
        ]);
    }

    public function op(PlayerManagerPlayerNamedRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot op players on a proxy server',
            ], 400);
        }

        $ops = $this->utils->configs($server)['ops.json'];
        if ($ops) {
            $data = json_decode($ops, true);
        } else {
            $data = [];
        }

        $name = $request->input('name');

        foreach ($data as $op) {
            if (strtolower($op['name']) === strtolower($name)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is already an operator',
                ], 400);
            }
        }

        $player = $this->cache->lookupName($server, $name);

        if (is_null($player)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data[] = [
            'uuid' => $player['uuid'],
            'name' => $player['name'],
            'level' => 4,
            'bypassesPlayerLimit' => true,
        ];

        $this->utils->saveConfig($server, 'ops.json', $data);

        try {
            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:op {$player['name']}");
            } else {
                $this->commandRepository->setServer($server)->send("op {$player['name']}");
            }
        } catch (\Throwable $e) {
        }

        Activity::event('server:player.op')
            ->property([
                'uuid' => $player['uuid'],
                'name' => $player['name'],
            ])
            ->log();

        return new JsonResponse([
            'success' => true
        ]);
    }

    public function deop(PlayerManagerPlayerRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot deop players on a proxy server',
            ], 400);
        }

        $ops = $this->utils->configs($server)['ops.json'];
        if ($ops) {
            $data = json_decode($ops, true);
        } else {
            $data = [];
        }

        $uuid = $request->input('uuid');

        $isOp = false;
        foreach ($data as $op) {
            if ($op['uuid'] === $uuid) {
                $isOp = true;
                break;
            }
        }

        if (!$isOp) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Player is not an operator',
            ], 400);
        }

        $player = $this->cache->lookupUuid($server, $uuid);

        if (is_null($player)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data = array_filter($data, function ($op) use ($player) {
            return $op['uuid'] !== $player['uuid'];
        });

        $this->utils->saveConfig($server, 'ops.json', $data);

        try {
            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:deop {$player['name']}");
            } else {
                $this->commandRepository->setServer($server)->send("deop {$player['name']}");
            }
        } catch (\Throwable $e) {
        }

        Activity::event('server:player.deop')
            ->property([
                'uuid' => $player['uuid'],
                'name' => $player['name'],
            ])
            ->log();

        return new JsonResponse([
            'success' => true
        ]);
    }

    public function setwhitelist(PlayerManagerSetWhitelistRequest $request, Server $server): array
    {
        if ($this->utils->isProxy($server)) {
            return [
                'success' => false,
                'error' => 'Cannot manage whitelist on a proxy server',
            ];
        }

        $properties = $this->utils->configs($server)['server.properties'];
        if ($properties) {
            $data = explode("\n", $properties);
        } else {
            $data = [];
        }

        $whitelist = $request->input('enabled');

        $data = array_map(function ($line) use ($whitelist) {
            if (str_starts_with($line, 'white-list=')) {
                return 'white-list=' . ($whitelist ? 'true' : 'false');
            }

            return $line;
        }, $data);

        if (!in_array('white-list=false', $data) && !in_array('white-list=true', $data)) {
            $data[] = 'white-list=' . ($whitelist ? 'true' : 'false');
        }

        $this->utils->saveConfig($server, 'server.properties', implode("\n", $data));

        try {
            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send('minecraft:whitelist ' . ($whitelist ? 'on' : 'off'));
            } else {
                $this->commandRepository->setServer($server)->send('whitelist ' . ($whitelist ? 'on' : 'off'));
            }
        } catch (\Throwable $e) {
        }

        Activity::event('server:whitelist.set')
            ->property([
                'enabled' => $whitelist,
            ])
            ->log();

        return [
            'success' => true
        ];
    }

    public function addwhitelist(PlayerManagerPlayerNamedRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot manage whitelist on a proxy server',
            ], 400);
        }

        $whitelist = $this->utils->configs($server)['whitelist.json'];
        if ($whitelist) {
            $data = json_decode($whitelist, true);
        } else {
            $data = [];
        }

        $name = $request->input('name');

        foreach ($data as $whitelist) {
            if (strtolower($whitelist['name']) === strtolower($name)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is already whitelisted',
                ], 400);
            }
        }

        $player = $this->cache->lookupName($server, $name);

        if (is_null($player)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data[] = [
            'uuid' => $player['uuid'],
            'name' => $player['name'],
        ];

        $this->utils->saveConfig($server, 'whitelist.json', $data);

        try {
            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:whitelist add {$player['name']}");
            } else {
                $this->commandRepository->setServer($server)->send("whitelist add {$player['name']}");
            }
        } catch (\Throwable $e) {
        }

        Activity::event('server:whitelist.add')
            ->property([
                'uuid' => $player['uuid'],
                'name' => $player['name'],
            ])
            ->log();

        return new JsonResponse([
            'success' => true
        ]);
    }

    public function removewhitelist(PlayerManagerPlayerRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot manage whitelist on a proxy server',
            ], 400);
        }

        $whitelist = $this->utils->configs($server)['whitelist.json'];
        if ($whitelist) {
            $data = json_decode($whitelist, true);
        } else {
            $data = [];
        }

        $uuid = $request->input('uuid');

        $isWhitelisted = false;
        foreach ($data as $whitelist) {
            if ($whitelist['uuid'] === $uuid) {
                $isWhitelisted = true;
                break;
            }
        }

        if (!$isWhitelisted) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Player is not whitelisted',
            ], 400);
        }

        $player = $this->cache->lookupUuid($server, $uuid);

        if (is_null($player)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data = array_filter($data, function ($whitelist) use ($player) {
            return $whitelist['uuid'] !== $player['uuid'];
        });

        $this->utils->saveConfig($server, 'whitelist.json', $data);

        try {
            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:whitelist remove {$player['name']}");
            } else {
                $this->commandRepository->setServer($server)->send("whitelist remove {$player['name']}");
            }
        } catch (\Throwable $e) {
        }

        Activity::event('server:whitelist.remove')
            ->property([
                'uuid' => $player['uuid'],
                'name' => $player['name'],
            ])
            ->log();

        return new JsonResponse([
            'success' => true
        ]);
    }

    public function ban(PlayerManagerBanRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot ban players on a proxy server',
            ], 400);
        }

        $bans = $this->utils->configs($server)['banned-players.json'];
        if ($bans) {
            $data = json_decode($bans, true);
        } else {
            $data = [];
        }

        $name = $request->input('name');
        $reason = $request->input('reason');

        foreach ($data as $ban) {
            if (strtolower($ban['name']) === strtolower($name)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is already banned',
                ], 400);
            }
        }

        $player = $this->cache->lookupName($server, $name);

        if (is_null($player)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data[] = [
            'uuid' => $player['uuid'],
            'name' => $player['name'],
            'source' => 'Server',
            'created' => time(),
            'expires' => 'forever',
            'reason' => $reason,
        ];

        $this->utils->saveConfig($server, 'banned-players.json', $data);

        try {
            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:ban {$player['name']} $reason");
            } else {
                $this->commandRepository->setServer($server)->send("ban {$player['name']} $reason");
            }
        } catch (\Throwable $e) {
        }

        Activity::event('server:player.ban')
            ->property([
                'uuid' => $player['uuid'],
                'name' => $player['name'],
                'reason' => $reason,
            ])
            ->log();

        return new JsonResponse([
            'success' => true
        ]);
    }

    public function unban(PlayerManagerPlayerRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot unban players on a proxy server',
            ], 400);
        }

        $bans = $this->utils->configs($server)['banned-players.json'];
        if ($bans) {
            $data = json_decode($bans, true);
        } else {
            $data = [];
        }

        $uuid = $request->input('uuid');

        $isBanned = false;
        foreach ($data as $ban) {
            if ($ban['uuid'] === $uuid) {
                $isBanned = true;
                break;
            }
        }

        if (!$isBanned) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Player is not banned',
            ], 400);
        }

        $player = $this->cache->lookupUuid($server, $uuid);

        if (is_null($player)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data = array_filter($data, function ($ban) use ($player) {
            return $ban['uuid'] !== $player['uuid'];
        });

        $this->utils->saveConfig($server, 'banned-players.json', $data);

        try {
            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:pardon {$player['name']}");
            } else {
                $this->commandRepository->setServer($server)->send("pardon {$player['name']}");
            }
        } catch (\Throwable $e) {
        }

        Activity::event('server:player.unban')
            ->property([
                'uuid' => $player['uuid'],
                'name' => $player['name'],
            ])
            ->log();

        return new JsonResponse([
            'success' => true
        ]);
    }

    public function banIp(PlayerManagerBanIpRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot ban IPs on a proxy server',
            ], 400);
        }

        $ip = $request->input('ip');
        $reason = $request->input('reason');

        $bans = $this->utils->configs($server)['banned-ips.json'];
        if ($bans) {
            $data = json_decode($bans, true);
        } else {
            $data = [];
        }

        foreach ($data as $ban) {
            if ($ban['ip'] === $ip) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'IP is already banned',
                ], 400);
            }
        }

        $data[] = [
            'ip' => $ip,
            'source' => 'Server',
            'created' => time(),
            'expires' => 'forever',
            'reason' => $reason,
        ];

        $this->utils->saveConfig($server, 'banned-ips.json', $data);

        try {
            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:ban-ip $ip $reason");
            } else {
                $this->commandRepository->setServer($server)->send("ban-ip $ip $reason");
            }
        } catch (\Throwable $e) {
        }

        Activity::event('server:player.ban-ip')
            ->property([
                'ip' => $ip,
                'reason' => $reason,
            ])
            ->log();

        return new JsonResponse([
            'success' => true
        ]);
    }

    public function banIpPlayer(PlayerManagerPlayerRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot ban IPs on a proxy server',
            ], 400);
        }

        $uuid = $request->input('uuid');
        $reason = $request->input('reason');

        try {
            $data = $this->queryApi($server);

            $name = null;
            foreach ($data['players']['list'] ?? [] as $player) {
                if ($player['id'] === $uuid) {
                    $name = $player['name'];
                    break;
                }
            }

            if (!$name) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is not online',
                ], 400);
            }

            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:ban-ip $name $reason");
            } else {
                $this->commandRepository->setServer($server)->send("ban-ip $name $reason");
            }

            Activity::event('server:player.ban-ip-player')
                ->property([
                    'uuid' => $uuid,
                    'name' => $name,
                    'reason' => $reason,
                ])
                ->log();

            return new JsonResponse([
                'success' => true
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server is offline',
            ], 400);
        }
    }

    public function unbanIp(PlayerManagerIpRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot unban IPs on a proxy server',
            ], 400);
        }

        $ip = $request->input('ip');

        $bans = $this->utils->configs($server)['banned-ips.json'];
        if ($bans) {
            $data = json_decode($bans, true);
        } else {
            $data = [];
        }

        $isBanned = false;
        foreach ($data as $ban) {
            if ($ban['ip'] === $ip) {
                $isBanned = true;
                break;
            }
        }

        if (!$isBanned) {
            return new JsonResponse([
                'success' => false,
                'error' => 'IP is not banned',
            ], 400);
        }

        $data = array_filter($data, function ($ban) use ($ip) {
            return $ban['ip'] !== $ip;
        });

        $this->utils->saveConfig($server, 'banned-ips.json', $data);

        try {
            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:pardon-ip $ip");
            } else {
                $this->commandRepository->setServer($server)->send("pardon-ip $ip");
            }
        } catch (\Throwable $e) {
        }

        Activity::event('server:player.unban-ip')
            ->property([
                'ip' => $ip,
            ])
            ->log();

        return new JsonResponse([
            'success' => true
        ]);
    }

    public function kick(PlayerManagerKickRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot kick players on a proxy server',
            ], 400);
        }

        $uuid = $this->utils->formatUuid($request->input('uuid'));
        $reason = $request->input('reason');

        try {
            $query = $this->queryApi($server);

            $name = null;
            foreach ($query['players']['list'] ?? [] as $player) {
                if ($player['id'] === $uuid) {
                    $name = $player['name'];
                    break;
                }
            }

            if (!$name) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is not online',
                ], 400);
            }

            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:kick $name $reason");
            } else {
                $this->commandRepository->setServer($server)->send("kick $name $reason");
            }

            Activity::event('server:player.kick')
                ->property([
                    'uuid' => $uuid,
                    'name' => $name,
                ])
                ->log();

            return new JsonResponse([
                'success' => true
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server is offline',
            ], 400);
        }
    }

    public function clear(PlayerManagerPlayerRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot clear players on a proxy server',
            ], 400);
        }

        $uuid = $this->utils->formatUuid($request->input('uuid'));

        try {
            $query = $this->queryApi($server);

            $name = null;
            foreach ($query['players']['list'] ?? [] as $player) {
                if ($player['id'] === $uuid) {
                    $name = $player['name'];
                    break;
                }
            }

            if (!$name) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is not online',
                ], 400);
            }

            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:clear $name");
            } else {
                $this->commandRepository->setServer($server)->send("clear $name");
            }

            Activity::event('server:player.clear')
                ->property([
                    'uuid' => $uuid,
                    'name' => $name,
                ])
                ->log();

            return new JsonResponse([
                'success' => true
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server is offline',
            ], 400);
        }
    }

    public function wipe(PlayerManagerPlayerRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot wipe players on a proxy server',
            ], 400);
        }

        $uuid = $this->utils->formatUuid($request->input('uuid'));

        try {
            $query = $this->queryApi($server);

            $name = null;
            foreach ($query['players']['list'] ?? [] as $player) {
                if ($player['id'] === $uuid) {
                    $name = $player['name'];
                    break;
                }
            }

            if ($name) {
                if ($this->utils->isBukkitBased($server)) {
                    $this->commandRepository->setServer($server)->send("minecraft:kick $name Wiped");
                } else {
                    $this->commandRepository->setServer($server)->send("kick $name Wiped");
                }
            }

            $properties = $this->utils->configs($server)['server.properties'];
            if (!$properties) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Failed to find level name',
                ], 400);
            }

            $data = explode("\n", $properties);
            $levelName = null;

            foreach ($data as $line) {
                if (str_starts_with($line, 'level-name=')) {
                    $levelName = explode('=', $line, 2)[1];
                    break;
                }
            }

            if (!$levelName) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Failed to find level name',
                ], 400);
            }

            $this->fileRepository->setServer($server)->deleteFiles($levelName, [
                "playerdata/$uuid.dat",
                "playerdata/$uuid.dat_old",
            ]);

            Activity::event('server:player.wipe')
                ->property([
                    'uuid' => $uuid,
                    'name' => $name,
                ])
                ->log();

            return new JsonResponse([
                'success' => true
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server is offline',
            ], 400);
        }
    }

    public function whisper(PlayerManagerWhisperRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot whisper players on a proxy server',
            ], 400);
        }

        $uuid = $this->utils->formatUuid($request->input('uuid'));
        $message = $request->input('message');

        try {
            $query = $this->queryApi($server);

            $name = null;
            foreach ($query['players']['list'] ?? [] as $player) {
                if ($player['id'] === $uuid) {
                    $name = $player['name'];
                    break;
                }
            }

            if (!$name) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is not online',
                ], 400);
            }

            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:tell $name $message");
            } else {
                $this->commandRepository->setServer($server)->send("tell $name $message");
            }

            Activity::event('server:player.whisper')
                ->property([
                    'uuid' => $uuid,
                    'name' => $name,
                    'message' => $message,
                ])
                ->log();

            return new JsonResponse([
                'success' => true
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server is offline',
            ], 400);
        }
    }

    public function kill(PlayerManagerPlayerRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot kill players on a proxy server',
            ], 400);
        }

        $uuid = $this->utils->formatUuid($request->input('uuid'));

        try {
            $query = $this->queryApi($server);

            $name = null;
            foreach ($query['players']['list'] ?? [] as $player) {
                if ($player['id'] === $uuid) {
                    $name = $player['name'];
                    break;
                }
            }

            if (!$name) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is not online',
                ], 400);
            }

            if ($this->utils->isBukkitBased($server)) {
                $this->commandRepository->setServer($server)->send("minecraft:kill $name");
            } else {
                $this->commandRepository->setServer($server)->send("kill $name");
            }

            Activity::event('server:player.kill')
                ->property([
                    'uuid' => $uuid,
                    'name' => $name,
                ])
                ->log();

            return new JsonResponse([
                'success' => true
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server is offline',
            ], 400);
        }
    }

    public function stats(PlayerManagerGetRequest $request, Server $server, string $uuid): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot get stats on a proxy server',
            ], 400);
        }

        $uuid = $this->utils->formatUuid($uuid);

        $properties = $this->utils->configs($server)['server.properties'];
        if (!$properties) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to find level name',
            ], 400);
        }

        $data = explode("\n", $properties);
        $levelName = null;

        foreach ($data as $line) {
            if (str_starts_with($line, 'level-name=')) {
                $levelName = explode('=', $line, 2)[1];
                break;
            }
        }

        if (!$levelName) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to find level name',
            ], 400);
        }

        try {
            $playerData = $this->fileRepository->setServer($server)->getContent("$levelName/playerdata/$uuid.dat");
            $playerStats = json_decode($this->fileRepository->setServer($server)->getContent("$levelName/stats/$uuid.json"), true);
            $nbt = new Service(new DataHandler());
            $tree = $nbt->readString(zlib_decode($playerData));

            $stats = [];

            $position = $tree->findChildByName('Pos');
            if ($position) {
                $stats['position'] = [
                    'x' => $position->getChildren()[0]->getValue(),
                    'y' => $position->getChildren()[1]->getValue(),
                    'z' => $position->getChildren()[2]->getValue(),
                ];
            }

            $gameType = $tree->findChildByName('playerGameType');
            if ($gameType) {
                switch ($gameType->getValue()) {
                    case 0:
                        $stats['gamemode'] = 'survival';
                        break;
                    case 1:
                        $stats['gamemode'] = 'creative';
                        break;
                    case 2:
                        $stats['gamemode'] = 'adventure';
                        break;
                    case 3:
                        $stats['gamemode'] = 'spectator';
                        break;
                }
            }

            $dimension = $tree->findChildByName('Dimension');
            if ($dimension) {
                $stats['world'] = $dimension->getValue();
            }

            $xpLevel = $tree->findChildByName('XpLevel');
            if ($xpLevel) {
                $stats['xp_level'] = $xpLevel->getValue();
            }

            $xpTotal = $tree->findChildByName('XpTotal');
            if ($xpTotal) {
                $stats['xp_total'] = $xpTotal->getValue();
            }

            try {
                $playTime = $playerStats['stats']['minecraft:custom']['minecraft:play_time'];
                if ($playTime) {
                    $stats['playtime'] = floor($playTime / 20);
                }
            } catch (\Throwable $e) {}

            try {
                $deaths = $playerStats['stats']['minecraft:custom']['minecraft:deaths'];
                if ($deaths) {
                    $stats['deaths'] = $deaths;
                }
            } catch (\Throwable $e) {}

            try {
                $kills = $playerStats['stats']['minecraft:custom']['minecraft:player_kills'];
                if ($kills) {
                    $stats['kills'] = $kills;
                }
            } catch (\Throwable $e) {}

            try {
                $lastDeath = $playerStats['stats']['minecraft:custom']['minecraft:time_since_death'];
                if ($lastDeath) {
                    $stats['last_death'] = floor($lastDeath / 20);
                }
            } catch (\Throwable $e) {}

            return new JsonResponse([
                'success' => true,
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => true,
                'stats' => [],
            ]);
        }
    }

    public function inventory(PlayerManagerGetRequest $request, Server $server, string $uuid): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot get inventory on a proxy server',
            ], 400);
        }

        $uuid = $this->utils->formatUuid($uuid);

        $properties = $this->utils->configs($server)['server.properties'];
        if (!$properties) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to find level name',
            ], 400);
        }

        $data = explode("\n", $properties);
        $levelName = null;

        foreach ($data as $line) {
            if (str_starts_with($line, 'level-name=')) {
                $levelName = explode('=', $line, 2)[1];
                break;
            }
        }

        if (!$levelName) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to find level name',
            ], 400);
        }

        try {
            $playerData = $this->fileRepository->setServer($server)->getContent("$levelName/playerdata/$uuid.dat");
            $nbt = new Service(new DataHandler());
            $tree = $nbt->readString(zlib_decode($playerData));

            $inventory = [];
            $inventoryNode = $tree->findChildByName('Inventory');
            if ($inventoryNode && !$inventoryNode->isLeaf()) {
                foreach ($inventoryNode->getChildren() as $itemNode) {
                    $slot = null;
                    $id = null;
                    $count = 1;
                    $displayName = null;

                    $slotNode = $itemNode->findChildByName('Slot');
                    if ($slotNode !== false) {
                        $slot = $slotNode->getValue();
                    }
                    $idNode = $itemNode->findChildByName('id');
                    if ($idNode !== false) {
                        $id = $idNode->getValue();
                    }
                    $countNode = $itemNode->findChildByName('Count');
                    if ($countNode !== false) {
                        $count = $countNode->getValue();
                    }
                    $tag = $itemNode->findChildByName('tag');
                    if ($tag !== false) {
                        $display = $tag->findChildByName('display');
                        if ($display !== false) {
                            $nameNode = $display->findChildByName('Name');
                            if ($nameNode !== false) {
                                $raw = $nameNode->getValue();
                                if (is_string($raw)) {
                                    $decoded = json_decode($raw, true);
                                    $displayName = is_array($decoded) && isset($decoded['text'])
                                        ? $decoded['text']
                                        : $raw;
                                }
                            }
                        }
                    }

                    if ($slot !== null && $id !== null) {
                        $inventory[] = [
                            'slot' => (int) $slot,
                            'id' => (string) $id,
                            'count' => (int) $count,
                            'displayName' => $displayName,
                        ];
                    }
                }
            }

            usort($inventory, fn ($a, $b) => $a['slot'] <=> $b['slot']);

            return new JsonResponse([
                'success' => true,
                'inventory' => $inventory,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to read player inventory',
                'inventory' => [],
            ], 500);
        }
    }
}
