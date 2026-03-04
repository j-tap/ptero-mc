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

        if (str_ends_with($path, '.jar'))
        {
            $this->fileRepository->setServer($server)->deleteFiles('/', [$path]);
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

        foreach ($files as $file) {
            if (!isset($file['name'])) {
                continue;
            }

            if (str_ends_with($file['name'], '.jar')) {

                $name = str_replace('.jar', '', $file['name']);

                // очистка имени плагина
                $cleanName = preg_replace('/[-_]?\d+(\.\d+)+$/', '', $name);
                $cleanName = str_replace(['-bukkit','-spigot','-paper'], '', strtolower($cleanName));
                $cleanName = trim($cleanName);

                $pluginId = Cache::remember("spiget_search_$cleanName", 86400, function () use ($cleanName) {

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

                $plugins[] = [
                    'plugin_id' => $pluginId ?? crc32($file['name']),
                    'plugin_name' => $name,
                    'filename' => $file['name'],
                ];
            }
        }

        return response()->json([
            'plugins' => $plugins,
        ]);
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