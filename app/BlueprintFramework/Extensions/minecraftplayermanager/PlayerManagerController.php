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
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Dependencies\Rickselby\Nbt\Tag;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerPlayerRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerWhisperRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Dependencies\Rickselby\Nbt\DataHandler;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerPlayerNamedRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerSetWhitelistRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerSetGamemodeRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerSetLevelRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerSetPositionRequest;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests\PlayerManagerInventorySlotRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class PlayerManagerController extends ClientApiController
{
    /**
     * Vanilla textures (mcasset.cloud). Update when you target a newer Minecraft asset pack.
     *
     * @var array<int, string>
     */
    private const ITEM_ICON_VANILLA_TEMPLATES = [
        'https://mcasset.cloud/1.21.4/assets/minecraft/textures/item/{path_uri}.png',
        'https://mcasset.cloud/1.21.4/assets/minecraft/textures/block/{path_uri}.png',
        'https://cdn.jsdelivr.net/gh/InventivetalentDev/minecraft-assets@1.21.4/assets/minecraft/textures/item/{path_uri}.png',
        'https://cdn.jsdelivr.net/gh/InventivetalentDev/minecraft-assets@1.21.4/assets/minecraft/textures/block/{path_uri}.png',
    ];

    public function __construct(
        private DaemonFileRepository $fileRepository,
        private DaemonCommandRepository $commandRepository,
        private PlayerManagerUtilities $utils,
        private PlayerManagerUserCache $cache,
        private AuthmePlayerDataService $authmePlayerDataService,
        private LicensePlayerDataService $licensePlayerDataService,
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

    /**
     * @param array<int, array<string, mixed>> $players
     * @return array<int, array<string, mixed>>
     */
    private function attachLicensedToPlayers(Server $server, array $players): array
    {
        if ($players === []) {
            return $players;
        }

        $licenseData = $this->licensePlayerDataService->load(
            $server,
            array_map(fn (array $player) => (string) ($player['name'] ?? ''), $players)
        );

        foreach ($players as $index => $player) {
            $nameKey = strtolower(trim((string) ($player['name'] ?? '')));
            $fromDb = $licenseData['players'][$nameKey]['licensed'] ?? null;
            $licensed = $fromDb;
            if ($licensed === null) {
                $licensed = $this->utils->inferLicensedFromMinecraftUuid(isset($player['uuid']) ? (string) $player['uuid'] : null);
            }
            $players[$index]['licensed'] = $licensed;
        }

        return $players;
    }

    private function normalizeDaemonDate(?string $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function extractPlaytimeSeconds(?string $statsRaw): ?int
    {
        if (!is_string($statsRaw) || trim($statsRaw) === '') {
            return null;
        }

        $stats = json_decode($statsRaw, true);
        if (!is_array($stats)) {
            return null;
        }

        $playTimeTicks = $stats['stats']['minecraft:custom']['minecraft:play_time'] ?? null;
        if (!is_numeric($playTimeTicks)) {
            return null;
        }

        $ticks = (int) $playTimeTicks;
        if ($ticks < 0) {
            return null;
        }

        return (int) floor($ticks / 20);
    }

    private function extractSessionCount(?string $statsRaw): ?int
    {
        if (!is_string($statsRaw) || trim($statsRaw) === '') {
            return null;
        }

        $stats = json_decode($statsRaw, true);
        if (!is_array($stats)) {
            return null;
        }

        $leaveGameCount = $stats['stats']['minecraft:custom']['minecraft:leave_game'] ?? null;
        if (!is_numeric($leaveGameCount)) {
            return null;
        }

        $sessions = (int) $leaveGameCount;
        if ($sessions < 0) {
            return null;
        }

        return $sessions;
    }

    /**
     * @return array<int, string>
     */
    private function itemIconUrlTemplates(): array
    {
        $merged = self::ITEM_ICON_VANILLA_TEMPLATES;

        $custom = trim((string) config('minecraftplayermanager.item_icons.custom_template', ''));
        if ($custom !== '') {
            $merged = array_merge($merged, [$custom]);
        }

        return array_values(array_unique($merged));
    }

    private function legacyNumericItemIdToNamespaced(int $legacyId): ?string
    {
        return match ($legacyId) {
            322 => 'minecraft:golden_apple',
            442 => 'minecraft:shield',
            default => null,
        };
    }

    private function normalizeInventoryItemIdString(string $raw): string
    {
        $idStr = trim($raw, " \t\n\r\0\x0B\"'");
        if ($idStr === '') {
            return '';
        }
        if (preg_match('/^([a-z0-9_.-]+:[a-z0-9_./-]+|[a-z0-9_./-]+)/i', $idStr, $matches)) {
            $idStr = $matches[1];
        }
        if (preg_match('/^-?\d+$/', $idStr)) {
            $mapped = $this->legacyNumericItemIdToNamespaced((int) $idStr);
            if ($mapped !== null) {
                return $mapped;
            }
        }
        $idStr = strtolower($idStr);
        if (strpos($idStr, ':') === false && !is_numeric($idStr)) {
            $idStr = 'minecraft:' . $idStr;
        }

        return $idStr;
    }

    /**
     * NBT Node::findChildByName is recursive and can return a nested "id" (e.g. banner pattern), not the item id.
     */
    private function nbtDirectChildByName(mixed $parent, string $name): mixed
    {
        if (!is_object($parent) || !method_exists($parent, 'getChildren')) {
            return false;
        }
        if (method_exists($parent, 'isLeaf') && $parent->isLeaf()) {
            return false;
        }
        foreach ($parent->getChildren() as $child) {
            if ((string) $child->getName() === $name) {
                return $child;
            }
        }

        return false;
    }

    private function stringContainsInsensitive(string $haystack, string $needle): bool
    {
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }

        return stripos($haystack, $needle) !== false;
    }

    private function nbtDirectChildByNames(mixed $parent, string ...$names): mixed
    {
        foreach ($names as $name) {
            $node = $this->nbtDirectChildByName($parent, $name);
            if ($node !== false) {
                return $node;
            }
        }

        return false;
    }

    private function nbtDirectNumericValue(mixed $parent, string ...$names): ?int
    {
        $node = $this->nbtDirectChildByNames($parent, ...$names);
        if ($node === false) {
            return null;
        }

        $value = $node->getValue();
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function nbtItemHasEnchantmentGlintOverride(mixed $root): bool
    {
        if (!is_object($root) || !method_exists($root, 'isLeaf') || $root->isLeaf()) {
            return false;
        }

        $components = $this->nbtDirectChildByName($root, 'components');
        if ($components === false || $components->isLeaf()) {
            return false;
        }

        foreach ($components->getChildren() as $child) {
            $key = strtolower((string) $child->getName());
            if (!str_contains($key, 'enchantment_glint_override')) {
                continue;
            }
            $value = $child->getValue();
            if ($value === true) {
                return true;
            }
            if (is_numeric($value) && (int) $value !== 0) {
                return true;
            }
            if (is_string($value) && strtolower(trim($value)) === 'true') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $root Item compound (often under "item" in 1.20.5+ saves)
     * @param mixed $tag  "tag" / "Tag" compound or false
     */
    private function inferInventoryShieldId(mixed $root, mixed $tag): ?string
    {
        if (is_object($root) && method_exists($root, 'isLeaf') && !$root->isLeaf()) {
            $components = $this->nbtDirectChildByName($root, 'components');
            if ($components !== false && !$components->isLeaf()) {
                foreach ($components->getChildren() as $child) {
                    $key = strtolower((string) $child->getName());
                    if (str_contains($key, 'banner_patterns') || str_contains($key, 'base_color')) {
                        return 'minecraft:shield';
                    }
                }
            }
        }

        if ($tag !== false && is_object($tag) && method_exists($tag, 'isLeaf') && !$tag->isLeaf()) {
            $blockEntity = $this->nbtDirectChildByName($tag, 'BlockEntityTag');
            if ($blockEntity !== false && !$blockEntity->isLeaf()) {
                $patterns = $this->nbtDirectChildByName($blockEntity, 'Patterns');
                if ($patterns !== false) {
                    return 'minecraft:shield';
                }
            }
        }

        return null;
    }

    private function resolveInventoryItemIconId(string $idStr, mixed $root, mixed $tag, ?string $displayName): string
    {
        $lower = strtolower($idStr);
        if ($lower === 'minecraft:golden_apple' || $lower === 'golden_apple') {
            if ($this->nbtItemHasEnchantmentGlintOverride($root)) {
                return 'minecraft:enchanted_golden_apple';
            }
            $legacyDamage = $this->nbtDirectNumericValue($root, 'Damage', 'damage')
                ?? $this->nbtDirectNumericValue($tag, 'Damage', 'damage');
            if ($legacyDamage === 1) {
                    return 'minecraft:enchanted_golden_apple';
            }
        }

        if (is_string($displayName) && trim($displayName) !== '') {
            $title = trim($displayName);

            if ($this->stringContainsInsensitive($title, 'shield') || $this->stringContainsInsensitive($title, 'щит')) {
                return 'minecraft:shield';
            }

            $isEnchApple = (
                ($this->stringContainsInsensitive($title, 'enchanted') && $this->stringContainsInsensitive($title, 'apple'))
                || ($this->stringContainsInsensitive($title, 'зачар') && $this->stringContainsInsensitive($title, 'яблок'))
            );
            if ($isEnchApple) {
                return 'minecraft:enchanted_golden_apple';
            }
        }

        return $idStr;
    }

    /** Only http(s) URLs; empty config yields null (UI hides the button). */
    private function externalStatsUrlForClient(): ?string
    {
        $raw = trim((string) config('minecraftplayermanager.external_stats_url', ''));
        if ($raw === '') {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) (parse_url($raw, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return $raw;
    }

    public function index(PlayerManagerGetRequest $request, Server $server): array
    {
        $externalStatsUrl = $this->externalStatsUrlForClient();
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
                    'avatar' => $this->avatarUrl($op['uuid']),
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
                    'avatar' => $this->avatarUrl($whitelist['uuid']),
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
                    'avatar' => $this->avatarUrl($ban['uuid']),
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
                if (!is_array($player)) {
                    continue;
                }

                $rawId = $player['id'] ?? $player['uuid'] ?? null;
                if (!is_string($rawId) || trim($rawId) === '') {
                    continue;
                }

                $name = $player['name'] ?? null;
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }

                $uuidCanonical = $this->utils->formatUuid(str_replace('-', '', $rawId));

                $players[] = [
                    'uuid' => $uuidCanonical,
                    'name' => $name,
                    'avatar' => $this->avatarUrl($uuidCanonical),
                ];
            }

            $players = $this->attachLicensedToPlayers($server, $players);

            return [
                'success' => true,
                'ping' => round((microtime(true) - $start) * 1000, 2),
                'online' => true,
                'online_mode' => !$this->utils->isOfflineMode($server),
                'is_proxy' => $this->utils->isProxy($server),
                'is_proxied' => $this->utils->isProxied($server),
                'external_stats_url' => $externalStatsUrl,
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
                'external_stats_url' => $externalStatsUrl,
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

        $players = Cache::remember("minecraftserver:offline:{$server->id}", 30, function () use ($server, $levelName) {
            // Include playerdata for everyone with a .dat file (including currently online players) so
            // stats / AuthMe / license merge runs for them; the UI marks Online from the status query.
            $cache = $this->cache->collect($server);
            $playerData = $this->fileRepository->setServer($server)->getDirectory("$levelName/playerdata");
            $players = [];
            $statsPaths = [];

            foreach ($playerData as $file) {
                $filename = $file['name'] ?? null;
                if (!is_string($filename) || !str_ends_with($filename, '.dat')) {
                    continue;
                }

                $uuid = str_replace('.dat', '', $filename);
                if (!preg_match('/^[a-fA-F0-9-]{32,36}$/', $uuid)) {
                    continue;
                }

                $name = $cache->get(str_replace('-', '', $uuid));
                if (!$name) {
                    continue;
                }

                $players[$uuid] = [
                    'uuid' => $uuid,
                    'name' => $name,
                    'avatar' => $this->avatarUrl($uuid),
                    'first_seen_at' => null,
                    'last_logout_at' => $this->normalizeDaemonDate($file['modified'] ?? null),
                    'playtime' => null,
                    'session_count' => null,
                    'has_session' => null,
                    'reg_ip' => null,
                    'ip' => null,
                    'world' => null,
                    'licensed' => null,
                ];

                $statsPaths[$uuid] = "$levelName/stats/$uuid.json";
            }

            if ($statsPaths !== []) {
                $statsContents = $this->fileRepository
                    ->setServer($server)
                    ->getContentsBatch(array_values($statsPaths), 256 * 1024);

                foreach ($statsPaths as $uuid => $path) {
                    $statsRaw = $statsContents[$path] ?? null;
                    $players[$uuid]['playtime'] = $this->extractPlaytimeSeconds($statsRaw);
                    $players[$uuid]['session_count'] = $this->extractSessionCount($statsRaw);
                }
            }

            $authmeData = $this->authmePlayerDataService->load(
                $server,
                array_map(fn (array $player) => $player['name'], array_values($players))
            );

            foreach ($players as $uuid => $player) {
                $nameKey = strtolower(trim((string) ($player['name'] ?? '')));
                $authmePlayer = $authmeData['players'][$nameKey] ?? null;
                if (!$authmePlayer) {
                    continue;
                }

                $players[$uuid]['first_seen_at'] = $authmePlayer['first_seen_at'] ?? null;
                $players[$uuid]['has_session'] = $authmePlayer['has_session'] ?? null;
                $players[$uuid]['reg_ip'] = $authmePlayer['reg_ip'] ?? null;
                $players[$uuid]['ip'] = $authmePlayer['ip'] ?? null;
                $players[$uuid]['world'] = $authmePlayer['world'] ?? null;
            }

            $licenseData = $this->licensePlayerDataService->load(
                $server,
                array_map(fn (array $player) => $player['name'], array_values($players))
            );

            foreach ($players as $uuid => $player) {
                $nameKey = strtolower(trim((string) ($player['name'] ?? '')));
                $fromDb = $licenseData['players'][$nameKey]['licensed'] ?? null;
                $players[$uuid]['licensed'] = $fromDb
                    ?? $this->utils->inferLicensedFromMinecraftUuid($player['uuid']);
            }

            return [
                'players' => $this->sortList(array_values($players)),
                'warnings' => array_merge($authmeData['warnings'] ?? [], $licenseData['warnings'] ?? []),
            ];
        });

        return new JsonResponse([
            'success' => true,
            'players' => $players['players'] ?? [],
            'warnings' => $players['warnings'] ?? [],
        ]);
    }

    private const MINESKIN_API = 'https://api.mineskin.org';
    private const MOJANG_PROFILE_API = 'https://sessionserver.mojang.com/session/minecraft/profile/%s';
    private const DEFAULT_AVATAR = 'https://crafatar.com/avatars/{uuid}?size=256&overlay';
    private const MOJANG_SKIN_SENTINEL = '__mojang__';

    private function applyUuidTemplate(?string $template, string $uuid): ?string
    {
        if (!is_string($template) || trim($template) === '') {
            return null;
        }

        return str_replace('{uuid}', str_replace('-', '', $uuid), $template);
    }

    private function mojangSkinTextureUrl(string $uuid): ?string
    {
        $uuidClean = str_replace('-', '', $uuid);
        if (!preg_match('/^[a-f0-9]{32}$/i', $uuidClean)) {
            return null;
        }

        $apiUrl = sprintf(self::MOJANG_PROFILE_API, $uuidClean);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Pterodactyl-PlayerManager/1.0\r\nAccept: application/json",
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($apiUrl, false, $context);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $profile = json_decode($raw, true);
        if (!is_array($profile) || !isset($profile['properties']) || !is_array($profile['properties'])) {
            return null;
        }

        foreach ($profile['properties'] as $property) {
            if (!is_array($property) || ($property['name'] ?? null) !== 'textures' || !isset($property['value'])) {
                continue;
            }

            $decoded = base64_decode((string) $property['value'], true);
            if (!is_string($decoded) || $decoded === '') {
                continue;
            }

            $textures = json_decode($decoded, true);
            $url = $textures['textures']['SKIN']['url'] ?? null;
            if (is_string($url) && trim($url) !== '') {
                return $url;
            }
        }

        return null;
    }

    private function avatarUrl(string $uuid): string
    {
        $template = config('minecraftplayermanager.avatar_url', self::DEFAULT_AVATAR);

        return $this->applyUuidTemplate($template, $uuid)
            ?? $this->applyUuidTemplate(self::DEFAULT_AVATAR, $uuid)
            ?? self::DEFAULT_AVATAR;
    }

    private function skinTextureUrl(string $uuid): ?string
    {
        $template = config('minecraftplayermanager.skin_url', self::MOJANG_SKIN_SENTINEL);

        if ($template === self::MOJANG_SKIN_SENTINEL) {
            return $this->mojangSkinTextureUrl($uuid);
        }

        return $this->applyUuidTemplate(is_string($template) ? $template : null, $uuid);
    }

    /**
     * Remote PNG/JPEG URL for the skin (used by skinTexture JSON and skinImage proxy).
     * UUID must already be formatted (canonical dashed).
     */
    private function resolveRemoteSkinTextureUrl(string $uuid): ?string
    {
        $skinUrl = $this->skinTextureUrl($uuid);

        $apiKey = env('MINESKIN_API_KEY');
        if ($apiKey) {
            $apiUrl = self::MINESKIN_API . '/get/uuid/' . $uuid;
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", [
                        'User-Agent: Pterodactyl-PlayerManager/1.0',
                        'Authorization: Bearer ' . $apiKey,
                    ]),
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
        }

        if ($skinUrl === null || trim((string) $skinUrl) === '') {
            $fallbackTemplate = trim((string) config('minecraftplayermanager.skin_preview_fallback_url', ''));
            if ($fallbackTemplate !== '') {
                $skinUrl = $this->applyUuidTemplate($fallbackTemplate, $uuid);
            }
        }

        $skinUrl = is_string($skinUrl) ? trim($skinUrl) : '';
        if ($skinUrl === '') {
            return null;
        }

        return $this->isSafeHttpUrlForSkinProxy($skinUrl) ? $skinUrl : null;
    }

    private function isSafeHttpUrlForSkinProxy(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        if ($host === '169.254.169.254' || str_starts_with($host, '127.')) {
            return false;
        }

        return true;
    }

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
        $remote = $this->resolveRemoteSkinTextureUrl($uuid);
        if ($remote === null) {
            return new JsonResponse([
                'skinUrl' => null,
                'capeUrl' => null,
            ]);
        }

        $proxyPath = '/api/client/extensions/minecraftplayermanager/servers/' . $server->uuid
            . '/skinImage?' . http_build_query(['uuid' => $uuid]);

        return new JsonResponse([
            'skinUrl' => $proxyPath,
            'capeUrl' => null,
        ]);
    }

    /**
     * Same-origin skin PNG for the 3D viewer (avoids browser CORS on external skin hosts).
     */
    public function skinImage(PlayerManagerGetRequest $request, Server $server): Response
    {
        $uuid = $request->query('uuid');
        if (!$uuid || strlen($uuid) < 32) {
            return new Response('Bad Request', 400);
        }

        $uuid = $this->utils->formatUuid($uuid);
        $remote = $this->resolveRemoteSkinTextureUrl($uuid);
        if ($remote === null) {
            return new Response('Not Found', 404);
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Pterodactyl-PlayerManager/1.0',
                    'Accept' => 'image/png,image/jpeg,image/*,*/*;q=0.8',
                ])
                ->get($remote);
        } catch (\Throwable) {
            return new Response('Bad Gateway', 502);
        }

        if (!$response->successful()) {
            return new Response('Bad Gateway', 502);
        }

        $body = $response->body();
        if (strlen($body) < 64) {
            return new Response('Bad Gateway', 502);
        }

        $contentType = 'image/png';
        if (str_starts_with($body, "\xff\xd8\xff")) {
            $contentType = 'image/jpeg';
        } elseif (!str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
            $contentType = $response->header('Content-Type') ?? 'application/octet-stream';
            if (!is_string($contentType) || !str_starts_with(strtolower($contentType), 'image/')) {
                $contentType = 'application/octet-stream';
            }
        }

        return new Response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=300',
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
            if ($this->utils->sameMinecraftUuid($op['uuid'] ?? null, $uuid)) {
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

        $utils = $this->utils;
        $data = array_filter($data, function ($op) use ($player, $utils) {
            return !$utils->sameMinecraftUuid($op['uuid'] ?? null, $player['uuid']);
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
            if ($this->utils->sameMinecraftUuid($whitelist['uuid'] ?? null, $uuid)) {
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

        $utils = $this->utils;
        $data = array_filter($data, function ($whitelist) use ($player, $utils) {
            return !$utils->sameMinecraftUuid($whitelist['uuid'] ?? null, $player['uuid']);
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
            if ($this->utils->sameMinecraftUuid($ban['uuid'] ?? null, $uuid)) {
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

        $utils = $this->utils;
        $data = array_filter($data, function ($ban) use ($player, $utils) {
            return !$utils->sameMinecraftUuid($ban['uuid'] ?? null, $player['uuid']);
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

        $uuid = $this->utils->formatUuid($request->input('uuid'));
        $reason = $request->input('reason');

        try {
            $data = $this->queryApi($server);

            $name = null;
            foreach ($data['players']['list'] ?? [] as $player) {
                if ($this->utils->sameMinecraftUuid($player['id'] ?? null, $uuid)) {
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
                if ($this->utils->sameMinecraftUuid($player['id'] ?? null, $uuid)) {
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
                if ($this->utils->sameMinecraftUuid($player['id'] ?? null, $uuid)) {
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
                if ($this->utils->sameMinecraftUuid($player['id'] ?? null, $uuid)) {
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
                if ($this->utils->sameMinecraftUuid($player['id'] ?? null, $uuid)) {
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
                if ($this->utils->sameMinecraftUuid($player['id'] ?? null, $uuid)) {
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

    private function getOnlinePlayerName(Server $server, string $uuid): ?string
    {
        try {
            $data = $this->queryApi($server);
            foreach ($data['players']['list'] ?? [] as $p) {
                if ($this->utils->sameMinecraftUuid($p['id'] ?? null, $uuid)) {
                    return $p['name'] ?? null;
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    private function normalizeInventorySlot(?int $slot): ?int
    {
        if (!is_int($slot)) {
            return null;
        }

        if ($slot < 0) {
            $slot += 256;
        }

        if ($slot === 150) {
            return 40;
        }

        return $slot;
    }

    private function resolveLevelName(Server $server): ?string
    {
        $properties = $this->utils->configs($server)['server.properties'];
        if (!$properties) {
            return null;
        }

        $data = explode("\n", $properties);
        foreach ($data as $line) {
            if (str_starts_with($line, 'level-name=')) {
                return explode('=', $line, 2)[1] ?? null;
            }
        }

        return null;
    }

    public function setGamemode(PlayerManagerSetGamemodeRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse(['success' => false, 'error' => 'Cannot set gamemode on a proxy server'], 400);
        }
        $uuid = $request->input('uuid');
        $name = $this->getOnlinePlayerName($server, $uuid);
        if (!$name) {
            return new JsonResponse(['success' => false, 'error' => 'Player must be online'], 400);
        }
        $mode = $request->input('mode');
        try {
            $cmd = $this->utils->isBukkitBased($server)
                ? "minecraft:gamemode $mode $name"
                : "gamemode $mode $name";
            $this->commandRepository->setServer($server)->send($cmd);
            Activity::event('server:player.gamemode')->property(['name' => $name, 'mode' => $mode])->log();
            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function setLevel(PlayerManagerSetLevelRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse(['success' => false, 'error' => 'Cannot set level on a proxy server'], 400);
        }
        $uuid = $request->input('uuid');
        $name = $this->getOnlinePlayerName($server, $uuid);
        if (!$name) {
            return new JsonResponse(['success' => false, 'error' => 'Player must be online'], 400);
        }
        $level = (int) $request->input('level');
        try {
            $cmd = $this->utils->isBukkitBased($server)
                ? "minecraft:xp set $name $level levels"
                : "xp set $name $level levels";
            $this->commandRepository->setServer($server)->send($cmd);
            Activity::event('server:player.level')->property(['name' => $name, 'level' => $level])->log();
            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function setPosition(PlayerManagerSetPositionRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse(['success' => false, 'error' => 'Cannot set position on a proxy server'], 400);
        }
        $uuid = $request->input('uuid');
        $name = $this->getOnlinePlayerName($server, $uuid);
        if (!$name) {
            return new JsonResponse(['success' => false, 'error' => 'Player must be online'], 400);
        }
        $x = $request->input('x');
        $y = $request->input('y');
        $z = $request->input('z');
        try {
            $cmd = $this->utils->isBukkitBased($server)
                ? "minecraft:tp $name $x $y $z"
                : "tp $name $x $y $z";
            $this->commandRepository->setServer($server)->send($cmd);
            Activity::event('server:player.position')->property(['name' => $name])->log();
            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
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

            $debugInfo = [];
            if (!$tree->isLeaf()) {
                $rootNames = [];
                $armorCandidates = [];
                foreach ($tree->getChildren() as $ch) {
                    $n = $ch->getName();
                    $rootNames[] = $n === null ? '(null)' : (string) $n;
                    if ($n !== null && stripos((string) $n, 'armor') !== false) {
                        $armorCandidates[] = ['name' => (string) $n, 'type' => $ch->getType(), 'children_count' => $ch->isLeaf() ? 0 : count($ch->getChildren())];
                    }
                }
                $debugInfo['root_tag_names'] = $rootNames;
                $debugInfo['armor_list_candidates'] = $armorCandidates;
            }

            $inventory = [];
            $parseItem = function ($itemNode, ?int $overrideSlot = null) {
                $slot = $overrideSlot;
                $id = null;
                $count = 1;
                $displayName = null;

                $root = $itemNode;
                $innerItem = $itemNode->findChildByName('item');
                if ($innerItem !== false && !$innerItem->isLeaf()) {
                    $root = $innerItem;
                }

                $slotNode = $root->findChildByName('Slot') ?: $root->findChildByName('slot');
                if ($slotNode !== false && $slot === null) {
                    $rawSlot = $slotNode->getValue();
                    $slot = is_numeric($rawSlot) ? (int) $rawSlot : null;
                    if ($slot !== null && $slot < 0) {
                        $slot += 256;
                    }
                    if ($slot === 150) {
                        $slot = 40;
                    }
                }

                $idNode = $root->findChildByName('id') ?: $root->findChildByName('Id') ?: $root->findChildByName('ID');
                if ($idNode !== false) {
                    $id = $idNode->getValue();
                }
                if (($id === null || (string) $id === '') && !$root->isLeaf()) {
                    foreach ($root->getChildren() as $child) {
                        $name = $child->getName();
                        if ($name === 'id' || $name === 'Id' || $name === 'ID') {
                            $val = $child->getValue();
                            if ($val !== null && (string) $val !== '') {
                                $id = $val;
                                break;
                            }
                        }
                    }
                }
                if (($id === null || (string) $id === '') && !$root->isLeaf()) {
                    foreach ($root->getChildren() as $child) {
                        $val = $child->getValue();
                        if (is_string($val) && $val !== '' && (strpos($val, 'minecraft:') === 0 || preg_match('/^[a-z0-9_]+$/i', $val))) {
                            $id = $val;
                            break;
                        }
                    }
                }
                if (($id === null || (string) $id === '') && !$root->isLeaf()) {
                    $components = $root->findChildByName('components');
                    if ($components !== false && !$components->isLeaf()) {
                        $typeNode = $components->findChildByName('minecraft:type');
                        if ($typeNode !== false) {
                            $typeVal = $typeNode->getValue();
                            if (is_string($typeVal)) {
                                $id = $typeVal;
                            }
                        }
                    }
                }
                $countNode = $root->findChildByName('Count') ?: $root->findChildByName('count');
                if ($countNode !== false) {
                    $count = (int) $countNode->getValue();
                }
                if (!$root->isLeaf()) {
                    foreach ($root->getChildren() as $child) {
                        $name = $child->getName();
                        if (($name === 'Count' || $name === 'count') && is_numeric($child->getValue())) {
                            $count = (int) $child->getValue();
                            break;
                        }
                    }
                }

                $tag = $root->findChildByName('tag') ?: $root->findChildByName('Tag');
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

                if ($slot !== null && $id !== null && (string) $id !== '' && $count > 0) {
                    $idStr = (string) $id;
                    if (strpos($idStr, ':') === false && is_numeric($idStr) === false) {
                        $idStr = 'minecraft:' . $idStr;
                    }
                    $outSlot = (int) $slot;
                    if ($outSlot === 150) {
                        $outSlot = 40;
                    }
                    return [
                        'slot' => $outSlot,
                        'id' => $idStr,
                        'count' => (int) $count,
                        'displayName' => $displayName,
                    ];
                }
                return null;
            };

            $armorSlotsOrder = [100, 101, 102, 103];
            $armorAdded = false;

            foreach (['ArmorItems', 'Armor', 'armor', 'equipment', 'Equipment'] as $armorListName) {
                $armorNode = $tree->findChildByName($armorListName);
                if ($armorNode && !$armorNode->isLeaf()) {
                    $children = $armorNode->getChildren();
                    $count = count($children);
                    $indices = [];
                    $slots = [];
                    if ($count >= 4 && $count <= 6) {
                        if ($count === 4) {
                            $indices = [0, 1, 2, 3];
                            $slots = [100, 101, 102, 103];
                        } else {
                            $indices = [1, 2, 3, 4, 5];
                            $slots = [101, 103, 102, 100, 40];
                        }
                    }
                    foreach ($indices as $idx => $i) {
                        if (isset($children[$i], $slots[$idx])) {
                            try {
                                $entry = $parseItem($children[$i], $slots[$idx]);
                                if ($entry !== null) {
                                    $inventory[] = $entry;
                                    $armorAdded = true;
                                }
                            } catch (\Throwable $e) {
                                continue;
                            }
                        }
                    }
                    if ($armorAdded) {
                        break;
                    }
                }
            }
            if (!$armorAdded && !$tree->isLeaf()) {
                foreach ($tree->getChildren() as $child) {
                    $name = $child->getName();
                    if ($name !== null && (stripos((string) $name, 'armor') !== false || stripos((string) $name, 'equipment') !== false) && $child->getType() === Tag::TAG_LIST) {
                        $listChildren = $child->getChildren();
                        if (count($listChildren) >= 4) {
                            for ($i = 0; $i < 4; $i++) {
                                try {
                                    $entry = $parseItem($listChildren[$i], $armorSlotsOrder[$i]);
                                    if ($entry !== null) {
                                        $inventory[] = $entry;
                                        $armorAdded = true;
                                    }
                                } catch (\Throwable $e) {
                                    continue;
                                }
                            }
                            if ($armorAdded) {
                                break;
                            }
                        }
                    }
                }
            }

            $slotsFilled = array_flip(array_column($inventory, 'slot'));
            $inventoryNode = $tree->findChildByName('Inventory') ?: $tree->findChildByName('inventory');
            if ($inventoryNode && !$inventoryNode->isLeaf()) {
                foreach ($inventoryNode->getChildren() as $itemNode) {
                    try {
                        $entry = $parseItem($itemNode);
                        if ($entry !== null) {
                            if (!isset($slotsFilled[$entry['slot']])) {
                                $inventory[] = $entry;
                                $slotsFilled[$entry['slot']] = true;
                            } else {
                                $idx = array_search($entry['slot'], array_column($inventory, 'slot'));
                                if ($idx !== false) {
                                    $inventory[$idx] = $entry;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }

            if (!isset($slotsFilled[40])) {
                foreach (['Offhand', 'offhand'] as $offhandTag) {
                    $offhandNode = $tree->findChildByName($offhandTag);
                    if ($offhandNode !== false && !$offhandNode->isLeaf()) {
                        try {
                            $entry = $parseItem($offhandNode, 40);
                            if ($entry !== null) {
                                $inventory[] = $entry;
                                $slotsFilled[40] = true;
                                break;
                            }
                        } catch (\Throwable $e) {
                            continue;
                        }
                    }
                }
            }

            $armorFromList = array_filter($inventory, fn ($e) => $e['slot'] >= 100 && $e['slot'] <= 103);
            if (count($armorFromList) === 0) {
                $armorSlotsReversed = [103, 102, 101, 100];
                foreach (['equipment', 'Equipment', 'ArmorItems', 'Armor', 'armor'] as $armorListName) {
                    $armorNode = $tree->findChildByName($armorListName);
                    if ($armorNode && !$armorNode->isLeaf()) {
                        $children = $armorNode->getChildren();
                        $count = count($children);
                        $indices = [];
                        $slots = [];
                        if ($count === 4) {
                            $indices = [0, 1, 2, 3];
                            $slots = [103, 102, 101, 100];
                        } elseif ($count >= 6) {
                            $indices = [1, 2, 3, 4, 5];
                            $slots = [101, 103, 102, 100, 40];
                        }
                        foreach ($indices as $idx => $i) {
                            if (isset($children[$i], $slots[$idx])) {
                                try {
                                    $entry = $parseItem($children[$i], $slots[$idx]);
                                    if ($entry !== null) {
                                        $inventory[] = $entry;
                                    }
                                } catch (\Throwable $e) {
                                    continue;
                                }
                            }
                        }
                        break;
                    }
                }
            }

            usort($inventory, fn ($a, $b) => $a['slot'] <=> $b['slot']);

            $hasArmor = count(array_filter($inventory, fn ($e) => $e['slot'] >= 100 && $e['slot'] <= 103)) > 0;
            $showDebug = $request->query('debug') === '1' || $request->query('debug') === 'true' || !$hasArmor;

            $response = [
                'success' => true,
                'inventory' => $inventory,
                'item_icon_templates' => $this->itemIconUrlTemplates(),
            ];
            if ($showDebug && !empty($debugInfo)) {
                $response['_debug'] = $debugInfo;
            }

            return new JsonResponse($response);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to read player inventory',
                'inventory' => [],
                'item_icon_templates' => $this->itemIconUrlTemplates(),
            ], 200);
        }
    }

    public function removeInventoryItem(PlayerManagerInventorySlotRequest $request, Server $server): JsonResponse
    {
        if ($this->utils->isProxy($server)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Cannot modify inventory on a proxy server',
            ], 400);
        }

        $uuid = $this->utils->formatUuid($request->input('uuid'));
        $slot = $this->normalizeInventorySlot((int) $request->input('slot'));
        if ($slot === null) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid inventory slot',
            ], 422);
        }

        $levelName = $this->resolveLevelName($server);
        if (!$levelName) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to find level name',
            ], 400);
        }

        try {
            $path = "$levelName/playerdata/$uuid.dat";
            $playerData = $this->fileRepository->setServer($server)->getContent($path);
            $decoded = zlib_decode($playerData);
            if (!is_string($decoded)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Failed to decode player data',
                ], 500);
            }

            $nbt = new Service(new DataHandler());
            $tree = $nbt->readString($decoded);
            if (!$tree) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Failed to parse player data',
                ], 500);
            }

            $removed = 0;
            $inventoryNode = $tree->findChildByName('Inventory') ?: $tree->findChildByName('inventory');
            if ($inventoryNode && !$inventoryNode->isLeaf()) {
                foreach ($inventoryNode->getChildren() as $itemNode) {
                    $slotNode = $itemNode->findChildByName('Slot') ?: $itemNode->findChildByName('slot');
                    if ($slotNode === false) {
                        continue;
                    }

                    $itemSlot = $this->normalizeInventorySlot(is_numeric($slotNode->getValue()) ? (int) $slotNode->getValue() : null);
                    if ($itemSlot !== $slot) {
                        continue;
                    }

                    $inventoryNode->removeChild($itemNode);
                    ++$removed;
                }
            }

            if ($slot === 40 && !$tree->isLeaf()) {
                foreach ($tree->getChildren() as $child) {
                    $name = $child->getName();
                    if ($name === 'Offhand' || $name === 'offhand') {
                        $tree->removeChild($child);
                        ++$removed;
                    }
                }
            }

            if ($removed === 0) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Item not found in requested slot',
                ], 404);
            }

            $written = zlib_encode($nbt->writeString($tree), ZLIB_ENCODING_GZIP);
            if (!is_string($written)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Failed to encode player data',
                ], 500);
            }

            $this->fileRepository->setServer($server)->putContent($path, $written);

            Activity::event('server:player.inventory.remove')
                ->property([
                    'uuid' => $uuid,
                    'slot' => $slot,
                    'removed_count' => $removed,
                ])
                ->log();

            return new JsonResponse([
                'success' => true,
                'slot' => $slot,
                'removed' => $removed,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to modify player inventory',
            ], 500);
        }
    }
}
