<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Pterodactyl\Actions\Plugins\FetchPlugins;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\InstallPluginRequest;
use Pterodactyl\Models\InstalledPlugin;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\PluginInstallerService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class PluginManagerController extends ClientApiController
{
    private DaemonFileRepository $fileRepository;

    public function __construct(DaemonFileRepository $fileRepository)
    {
        parent::__construct();
        $this->fileRepository = $fileRepository;
    }

    public function index(Request $request, Server $server): JsonResponse
    {
        $query = $request->input('q');
        $page = (int) $request->input('page', 1);
        $perPage = 18;

        $plugins = (new FetchPlugins())->handle($query, $page, $perPage);
        $uuid = $server->uuid;

        $installed = InstalledPlugin::where('server_uuid', $uuid)->pluck('plugin_id')->toArray();

        return response()->json([
            'plugins' => collect($plugins)->map(fn($plugin) => [
                ...$plugin,
                'installed' => in_array($plugin['id'], $installed),
            ]),
            'meta' => [
                'pagination' => [
                    'total' => 1000,
                    'count' => count($plugins),
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => ceil(1000 / $perPage),
                ],
            ],
        ]);
    }

    public function install(InstallPluginRequest $request, Server $server, int $pluginId): JsonResponse
    {
        $uuid = $server->uuid;

        $service = new PluginInstallerService();
        $pluginName = $request->input('plugin_name');
        $service->install($uuid, $pluginId, $pluginName);

        Activity::event('server:plugin.install')
            ->subject($server)
            ->property('name', 'Installed plugin ' . $pluginName)
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    public function uninstall(Server $server, int $pluginId): JsonResponse
    {
        $uuid = $server->uuid;
        $plugin = InstalledPlugin::where('server_uuid', $uuid)->where('plugin_id', $pluginId)->firstOrFail();

        $path = 'plugins/' . ltrim($plugin->filename);
        if (!str_ends_with($path, '.jar')) {
            $plugin->delete();
            return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
        }
        $repo = $this->fileRepository->setServer($server);
        try {
            $repo->deleteFiles('/', [$path]);
        } catch (\Throwable $e) {
        }
        try {
            $repo->deleteFiles('/', [$path . '.disabled']);
        } catch (\Throwable $e) {
        }
        $plugin->delete();

        Activity::event('server:plugin.uninstall')
            ->subject($server)
            ->property('name', 'Deleted plugin ' . $plugin->filename)
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    public function installed(Server $server): JsonResponse
    {
        $plugins = [];

        try {
            $files = $this->fileRepository
                ->setServer($server)
                ->getDirectory('plugins');
        } catch (\Exception $e) {
            return response()->json([
                'plugins' => [],
            ]);
        }

        $dirNames = [];
        foreach ($files as $item) {
            if (Arr::get($item, 'file', true) === false && isset($item['name'])) {
                $dirNames[] = $item['name'];
            }
        }

        foreach ($files as $file) {
            if (!isset($file['name'])) {
                continue;
            }

            $disabled = false;
            $filename = $file['name'];

            if (str_ends_with($filename, '.jar.disabled')) {
                $disabled = true;
                $name = str_replace('.jar.disabled', '', $filename);
            } elseif (str_ends_with($filename, '.jar')) {
                $name = str_replace('.jar', '', $filename);
            } else {
                continue;
            }

            $cleanName = preg_replace('/[-_]?\d+(\.\d+)+$/', '', $name);
            $cleanName = str_replace(['-bukkit', '-spigot', '-paper'], '', strtolower($cleanName));
            $cleanName = trim($cleanName);

            $pluginIdFromSpiget = Cache::remember("spiget_search_$cleanName", 86400, function () use ($cleanName) {
                try {
                    $resp = Http::timeout(3)
                        ->get('https://api.spiget.org/v2/search/resources/' . urlencode($cleanName) . '?size=1');

                    if ($resp->ok() && count($resp->json()) > 0) {
                        return $resp->json()[0]['id'];
                    }
                } catch (\Throwable $e) {
                }

                return null;
            });

            $pluginId = $pluginIdFromSpiget ?? crc32($filename);

            $tag = null;
            if ($pluginIdFromSpiget !== null) {
                $tag = Cache::remember("spiget_resource_{$pluginIdFromSpiget}_tag", 86400, function () use ($pluginIdFromSpiget) {
                    try {
                        $resp = Http::timeout(2)
                            ->get("https://api.spiget.org/v2/resources/{$pluginIdFromSpiget}?fields=tag");
                        if ($resp->ok()) {
                            $data = $resp->json();
                            return $data['tag'] ?? null;
                        }
                    } catch (\Throwable $e) {
                    }
                    return null;
                });
            }

            $configFolder = $this->findConfigFolder($name, $dirNames);

            $plugins[] = [
                'plugin_id' => $pluginId,
                'plugin_name' => $name,
                'filename' => $filename,
                'disabled' => $disabled,
                'tag' => $tag,
                'config_folder' => $configFolder,
            ];
        }

        return response()->json([
            'plugins' => $plugins,
        ]);
    }

    private function findConfigFolder(string $pluginName, array $dirNames): ?string
    {
        $pluginLower = strtolower($pluginName);
        $candidates = [];
        foreach ($dirNames as $dir) {
            $dirLower = strtolower($dir);
            if ($dirLower === $pluginLower) {
                return $dir;
            }
            if (str_contains($pluginLower, $dirLower) || str_contains($dirLower, $pluginLower)) {
                $candidates[] = $dir;
            }
        }
        if (empty($candidates)) {
            return null;
        }
        usort($candidates, fn (string $a, string $b) => strlen($b) - strlen($a));

        return $candidates[0];
    }

    public function toggleDisable(Request $request, Server $server): JsonResponse
    {
        $filename = $request->input('filename');
        if (!is_string($filename) || !preg_match('/^[a-zA-Z0-9_.\-]+\.jar(\.disabled)?$/', $filename)) {
            return response()->json(['error' => 'Invalid filename.'], 422);
        }

        $root = 'plugins';
        $isDisabled = str_ends_with($filename, '.disabled');
        $from = $filename;
        $to = $isDisabled
            ? substr($filename, 0, -strlen('.disabled'))
            : $filename . '.disabled';

        $this->fileRepository
            ->setServer($server)
            ->renameFiles($root, [['from' => $from, 'to' => $to]]);

        Activity::event($isDisabled ? 'server:plugin.enable' : 'server:plugin.disable')
            ->subject($server)
            ->property('filename', $filename)
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    public function eggs(): JsonResponse
    {
        $path = 'plugin_manager/eggs.json';

        if (!Storage::exists($path)) {
            return response()->json(['error' => 'Config not found.'], 404);
        }

        $json = Storage::get($path);

        $decoded = json_decode($json, true);

        return response()->json($decoded);
    }
}