<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Transformers\Api\Client\FileObjectTransformer;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\CopyFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\PullFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\ListFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\ChmodFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\DeleteFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\RenameFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\CreateFolderRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\CompressFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\DecompressFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\GetFileContentsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\WriteFileContentRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\SearchFilesRequest;

class FileController extends ClientApiController
{
    private const SEARCHABLE_EXTENSIONS = [
        'txt', 'log', 'md', 'yml', 'yaml', 'json', 'properties', 'conf', 'cfg', 'ini', 'env',
        'xml', 'html', 'htm', 'css', 'js', 'ts', 'jsx', 'tsx', 'sh', 'bat', 'ps1',
        'php', 'py', 'rb', 'go', 'rs', 'java', 'kt', 'c', 'h', 'cpp', 'hpp',
        'toml', 'lock', 'sql', 'csv', 'gradle', 'kts', 'vue', 'svelte', 'scss', 'sass',
        'lua', 'groovy', 'scala', 'swift', 'm', 'mm', 'r', 'R',
        'sk',
    ];

    private const SEARCH_EXCLUDED_EXTENSIONS = [
        'zip', 'jar', 'war', 'ear', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'zst', 'lz', 'lz4',
        'dll', 'exe', 'so', 'dylib', 'class', 'pyc', 'pyo', 'o', 'a', 'lib',
        'png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'bmp', 'svg', 'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp3', 'wav', 'ogg', 'flac', 'mp4', 'webm', 'mkv', 'mov', 'avi',
        'db', 'sqlite', 'sqlite3',
    ];

    private const SEARCHABLE_NO_EXT_NAMES = [
        'README', 'LICENSE', 'CHANGELOG',
    ];

    private const SEARCHABLE_FILENAMES = [
        'dockerfile', 'makefile',
    ];

    private const SEARCH_MAX_DEPTH = 25;

    private const SEARCH_MAX_FILE_SIZE = 256 * 1024;

    private const SNIPPET_LENGTH = 120;

    private const SEARCH_BATCH_SIZE = 50;

    private const FILE_NAME_MATCH_LABEL = 'Filename match';

    private const SEARCH_PATHS_CACHE_TTL_SECONDS = 120;

    /**
     * FileController constructor.
     */
    public function __construct(
        private NodeJWTService $jwtService,
        private DaemonFileRepository $fileRepository,
    ) {
        parent::__construct();
    }

    /**
     * Returns a listing of files in a given directory.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function directory(ListFilesRequest $request, Server $server): array
    {
        $contents = $this->fileRepository
            ->setServer($server)
            ->getDirectory($request->get('directory') ?? '/');

        return $this->fractal->collection($contents)
            ->transformWith($this->getTransformer(FileObjectTransformer::class))
            ->toArray();
    }

    /**
     * Return the contents of a specified file for the user.
     *
     * @throws \Throwable
     */
    public function contents(GetFileContentsRequest $request, Server $server): Response
    {
        $response = $this->fileRepository->setServer($server)->getContent(
            $request->get('file'),
            config('pterodactyl.files.max_edit_size')
        );

        Activity::event('server:file.read')->property('file', $request->get('file'))->log();

        return new Response($response, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    /**
     * Search file contents in the given directory and all nested directories. Returns matching paths and snippets.
     * With ?stream=1 returns Server-Sent Events and sends results incrementally.
     */
    public function search(SearchFilesRequest $request, Server $server): array|StreamedResponse
    {
        if ($request->query('stream')) {
            return $this->searchStream($request, $server);
        }

        set_time_limit(120);
        $dir = $request->get('directory');
        $q = $request->input('q');
        $repo = $this->fileRepository->setServer($server);

        $paths = $this->getSearchablePathsCached($server, $repo, $dir);

        $maxSize = min(config('pterodactyl.files.max_edit_size'), self::SEARCH_MAX_FILE_SIZE);
        $results = [];
        $seenPaths = [];
        $pathChunks = array_chunk($paths, self::SEARCH_BATCH_SIZE);
        $contentsFetchedTotal = 0;

        foreach ($paths as $path) {
            if (mb_stripos(basename($path), $q) === false) {
                continue;
            }
            $results[] = [
                'path' => $path,
                'snippet' => self::FILE_NAME_MATCH_LABEL . ': ' . basename($path),
                'source' => 'filename',
            ];
            $seenPaths[$path] = true;
        }

        foreach ($pathChunks as $chunk) {
            $pathsWithSlash = array_map(fn ($p) => str_starts_with($p, '/') ? $p : '/' . $p, $chunk);
            $pathsNoSlash = array_map(fn ($p) => str_starts_with($p, '/') ? ltrim($p, '/') : $p, $chunk);
            $contents = $repo->getContentsBatch($pathsWithSlash, $maxSize, self::SEARCH_BATCH_SIZE);
            if ($contents === [] && $chunk !== []) {
                $contents = $repo->getContentsBatch($pathsNoSlash, $maxSize, self::SEARCH_BATCH_SIZE);
            }
            $contentsFetchedTotal += count($contents);
            foreach ($contents as $wingsPath => $content) {
                $relativePath = str_starts_with($wingsPath, '/') ? ltrim($wingsPath, '/') : $wingsPath;
                $pos = mb_stripos($content, $q);
                if ($pos === false) {
                    continue;
                }
                if (isset($seenPaths[$relativePath])) {
                    continue;
                }
                $start = max(0, $pos - (int) (self::SNIPPET_LENGTH / 2));
                $snippet = mb_substr($content, $start, self::SNIPPET_LENGTH);
                $snippet = preg_replace('/\s+/', ' ', trim($snippet));
                if (mb_strlen($snippet) > self::SNIPPET_LENGTH) {
                    $snippet = mb_substr($snippet, 0, self::SNIPPET_LENGTH - 1) . '…';
                }
                $results[] = [
                    'path' => $relativePath,
                    'snippet' => $snippet,
                    'source' => 'content',
                ];
                $seenPaths[$relativePath] = true;
            }
        }

        return [
            'object' => 'list',
            'data' => array_values($results),
            'meta' => [
                'debug' => [
                    'directory' => $dir,
                    'paths_count' => count($paths),
                    'sample_paths' => array_slice($paths, 0, 5),
                    'chunks_count' => count($pathChunks),
                    'contents_fetched' => $contentsFetchedTotal,
                    'matches_count' => count($results),
                    'note' => null,
                ],
            ],
        ];
    }

    private function searchStream(SearchFilesRequest $request, Server $server): StreamedResponse
    {
        $dir = $request->get('directory');
        $q = $request->input('q');
        $repo = $this->fileRepository->setServer($server);
        $maxSize = min(config('pterodactyl.files.max_edit_size'), self::SEARCH_MAX_FILE_SIZE);

        $send = function (string $event, array $data) {
            echo 'event: ' . $event . "\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        };

        return new StreamedResponse(function () use ($dir, $q, $repo, $maxSize, $send, $server) {
            set_time_limit(0);
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');

            $debugCollect = ['dirs_listed' => [], 'dirs_failed' => []];
            $send('start', [
                'stage' => 'collecting',
                'paths_count' => null,
                'chunks_count' => null,
            ]);

            $dirsVisited = 0;
            $lastProgressAt = microtime(true);
            $paths = $this->getSearchablePathsCached(
                $server,
                $repo,
                $dir,
                $debugCollect,
                function () use (&$dirsVisited, &$lastProgressAt, $send) {
                    $dirsVisited++;
                    $now = microtime(true);
                    if (($now - $lastProgressAt) < 1.0) {
                        return;
                    }
                    $lastProgressAt = $now;
                    $send('progress', ['stage' => 'collecting', 'dirs_visited' => $dirsVisited]);
                }
            );
            $priorityPaths = [];
            $normalPaths = [];
            foreach ($paths as $path) {
                $isPriority = stripos($path, 'plugins/') === 0 && preg_match('#/config\.(yml|yaml|json)$#i', $path);
                if ($isPriority) {
                    $priorityPaths[] = $path;
                } else {
                    $normalPaths[] = $path;
                }
            }
            $paths = array_merge($priorityPaths, $normalPaths);
            $pathChunks = array_chunk($paths, self::SEARCH_BATCH_SIZE);
            $contentsFetchedTotal = 0;
            $matchesCount = 0;

            $pathTabInList = in_array('plugins/TAB/config.yml', $paths, true)
                || in_array('TAB/config.yml', $paths, true);
            $pathsContainingTab = [];
            foreach ($paths as $path) {
                if (stripos($path, 'TAB') === false) {
                    continue;
                }
                $pathsContainingTab[] = $path;
                if (count($pathsContainingTab) >= 50) {
                    break;
                }
            }

            $send('start', [
                'stage' => 'searching',
                'paths_count' => count($paths),
                'chunks_count' => count($pathChunks),
            ]);
            $seenPaths = [];

            foreach ($paths as $path) {
                if (mb_stripos(basename($path), $q) === false) {
                    continue;
                }
                $send('result', [
                    'path' => $path,
                    'snippet' => self::FILE_NAME_MATCH_LABEL . ': ' . basename($path),
                    'source' => 'filename',
                ]);
                $seenPaths[$path] = true;
                $matchesCount++;
            }

            foreach ($pathChunks as $chunk) {
                if (connection_aborted()) {
                    break;
                }
                $pathsWithSlash = array_map(fn ($p) => str_starts_with($p, '/') ? $p : '/' . $p, $chunk);
                $pathsNoSlash = array_map(fn ($p) => str_starts_with($p, '/') ? ltrim($p, '/') : $p, $chunk);
                $contents = $repo->getContentsBatch($pathsWithSlash, $maxSize, self::SEARCH_BATCH_SIZE);
                if ($contents === [] && $chunk !== []) {
                    $contents = $repo->getContentsBatch($pathsNoSlash, $maxSize, self::SEARCH_BATCH_SIZE);
                }
                $missing = array_diff($chunk, array_map(fn ($p) => str_starts_with($p, '/') ? ltrim($p, '/') : $p, array_keys($contents)));
                foreach ($missing as $relPath) {
                    $variants = $this->pathCaseVariants($relPath);
                    foreach ($variants as $variant) {
                        foreach ([$variant, '/' . ltrim($variant, '/')] as $pathToTry) {
                            try {
                                $content = $repo->getContent($pathToTry, $maxSize);
                                $contents['/' . ltrim($relPath, '/')] = $content;
                                break 2;
                            } catch (\Throwable) {
                                continue;
                            }
                        }
                    }
                }
                $contentsFetchedTotal += count($contents);
                foreach ($contents as $wingsPath => $content) {
                    $relativePath = str_starts_with($wingsPath, '/') ? ltrim($wingsPath, '/') : $wingsPath;
                    if (isset($seenPaths[$relativePath])) {
                        continue;
                    }
                    $pos = mb_stripos($content, $q);
                    if ($pos === false) {
                        continue;
                    }
                    $start = max(0, $pos - (int) (self::SNIPPET_LENGTH / 2));
                    $snippet = mb_substr($content, $start, self::SNIPPET_LENGTH);
                    $snippet = preg_replace('/\s+/', ' ', trim($snippet));
                    if (mb_strlen($snippet) > self::SNIPPET_LENGTH) {
                        $snippet = mb_substr($snippet, 0, self::SNIPPET_LENGTH - 1) . '…';
                    }
                    $matchesCount++;
                    $send('result', ['path' => $relativePath, 'snippet' => $snippet, 'source' => 'content']);
                    $seenPaths[$relativePath] = true;
                }
            }

            $send('done', [
                'debug' => [
                    'directory' => $dir,
                    'paths_count' => count($paths),
                    'chunks_count' => count($pathChunks),
                    'contents_fetched' => $contentsFetchedTotal,
                    'matches_count' => $matchesCount,
                    'note' => null,
                    'dirs_listed_count' => count($debugCollect['dirs_listed'] ?? []),
                    'dirs_failed' => $debugCollect['dirs_failed'] ?? [],
                    'path_plugins_tab_config_in_list' => $pathTabInList,
                    'paths_containing_tab' => $pathsContainingTab,
                ],
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Recursively collect paths of searchable files in directory and subdirectories.
     *
     * @param  array<int, string>  $collected
     * @param  array{dirs_listed?: list<string>, dirs_failed?: list<string>}  $debug
     * @return array<int, string>
     */
    private function collectSearchablePaths(
        DaemonFileRepository $repo,
        string $dir,
        int $depth,
        int $limit,
        array &$collected = [],
        ?array &$debug = null,
        ?callable $onDirectoryVisited = null
    ): array
    {
        if ($depth >= self::SEARCH_MAX_DEPTH) {
            return $collected;
        }
        if ($limit !== PHP_INT_MAX && count($collected) >= $limit) {
            return $collected;
        }

        $dirForWings = $dir === '/' ? '/' : ltrim($dir, '/');
        if ($onDirectoryVisited !== null) {
            $onDirectoryVisited();
        }
        if ($debug !== null) {
            $debug['dirs_listed'] = $debug['dirs_listed'] ?? [];
            $debug['dirs_failed'] = $debug['dirs_failed'] ?? [];
        }
        try {
            $contents = $repo->getDirectory($dirForWings);
            if ($debug !== null) {
                $debug['dirs_listed'][] = $dirForWings;
            }
        } catch (\Throwable) {
            $contents = null;
            if ($dirForWings !== '/' && $dirForWings === ltrim($dir, '/')) {
                try {
                    $contents = $repo->getDirectory($dir);
                    if ($debug !== null) {
                        $debug['dirs_listed'][] = $dir;
                    }
                } catch (\Throwable) {
                    // skip
                }
            }
            if ($contents === null && $dirForWings !== '/') {
                $segments = explode('/', $dirForWings);
                $last = array_pop($segments);
                $base = implode('/', $segments);
                foreach ([$last, strtolower($last), ucfirst(strtolower($last)), strtoupper($last)] as $variant) {
                    if ($variant === $last) {
                        continue;
                    }
                    $tryPath = $base === '' ? $variant : $base . '/' . $variant;
                    try {
                        $contents = $repo->getDirectory($tryPath);
                        if ($debug !== null) {
                            $debug['dirs_listed'][] = $tryPath . ' (case variant)';
                        }
                        $dirForWings = $tryPath;
                        $dir = '/' . ltrim($tryPath, '/');
                        break;
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }
            if ($contents === null) {
                if ($debug !== null) {
                    $debug['dirs_failed'][] = $dirForWings;
                }
                return $collected;
            }
        }

        if (isset($contents['data']) && is_array($contents['data'])) {
            $contents = $contents['data'];
        } elseif (isset($contents['files']) && is_array($contents['files'])) {
            $contents = $contents['files'];
        }
        if (!is_array($contents)) {
            return $collected;
        }

        usort($contents, fn ($a, $b) => strcasecmp(Arr::get($a, 'name', ''), Arr::get($b, 'name', '')));

        $subdirs = [];
        foreach ($contents as $item) {
            $name = Arr::get($item, 'name', '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $mime = Arr::get($item, 'mime', '');
            $fileFlag = Arr::get($item, 'file', true);
            $isDir = filter_var(Arr::get($item, 'dir', false), FILTER_VALIDATE_BOOLEAN)
                || filter_var(Arr::get($item, 'directory', false), FILTER_VALIDATE_BOOLEAN)
                || $fileFlag === false || $fileFlag === 'false' || $fileFlag === 0
                || str_starts_with((string) $mime, 'inode/directory');
            $prefix = ($dir === '/' ? '' : $dir) . '/' . $name;
            $relativePath = ltrim($prefix, '/');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!$isDir && $ext === '' && !$this->isSearchableNoExtName(pathinfo($name, PATHINFO_FILENAME))) {
                $isDir = true;
            }
            $isFile = !$isDir;

            if ($isFile) {
                if (in_array($ext, self::SEARCH_EXCLUDED_EXTENSIONS, true)) {
                    continue;
                }
                $nameOnly = strtolower(pathinfo($name, PATHINFO_FILENAME));
                $searchable = in_array($ext, self::SEARCHABLE_EXTENSIONS, true)
                    || ($ext === '' && $this->isSearchableNoExtName(pathinfo($name, PATHINFO_FILENAME)))
                    || in_array($nameOnly, self::SEARCHABLE_FILENAMES, true);
                if ($searchable) {
                    $collected[] = $relativePath;
                    if ($limit !== PHP_INT_MAX && count($collected) >= $limit) {
                        return $collected;
                    }
                }
            } else {
                $subdirs[] = $dir === '/' ? '/' . $name : $prefix;
            }
        }

        foreach ($subdirs as $subdir) {
            $this->collectSearchablePaths($repo, $subdir, $depth + 1, $limit, $collected, $debug, $onDirectoryVisited);
            if ($limit !== PHP_INT_MAX && count($collected) >= $limit) {
                return $collected;
            }
        }

        return $collected;
    }

    /**
     * @param  array{dirs_listed?: list<string>, dirs_failed?: list<string>, cache_hit?: bool}|null  $debug
     * @return array<int, string>
     */
    private function getSearchablePathsCached(
        Server $server,
        DaemonFileRepository $repo,
        string $dir,
        ?array &$debug = null,
        ?callable $onDirectoryVisited = null
    ): array {
        $cacheKey = $this->searchPathsCacheKey($server, $dir);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            if ($debug !== null) {
                $debug['cache_hit'] = true;
            }

            return $cached;
        }

        $collected = [];
        $paths = $this->collectSearchablePaths($repo, $dir, 0, PHP_INT_MAX, $collected, $debug, $onDirectoryVisited);
        Cache::put($cacheKey, $paths, now()->addSeconds(self::SEARCH_PATHS_CACHE_TTL_SECONDS));
        $this->trackSearchPathsCacheKey($server, $cacheKey);
        if ($debug !== null) {
            $debug['cache_hit'] = false;
        }

        return $paths;
    }

    private function searchPathsCacheKey(Server $server, string $dir): string
    {
        $normalizedDir = $this->normalizeSearchDirectory($dir);

        return sprintf('server:%s:file-search-paths:%s', $server->uuid, sha1($normalizedDir));
    }

    private function searchPathsIndexCacheKey(Server $server): string
    {
        return sprintf('server:%s:file-search-paths:index', $server->uuid);
    }

    private function normalizeSearchDirectory(string $dir): string
    {
        $normalized = str_replace('\\', '/', trim($dir));
        if ($normalized === '' || $normalized === '/') {
            return '/';
        }

        return '/' . ltrim($normalized, '/');
    }

    private function trackSearchPathsCacheKey(Server $server, string $cacheKey): void
    {
        $indexKey = $this->searchPathsIndexCacheKey($server);
        $keys = Cache::get($indexKey, []);
        if (!is_array($keys)) {
            $keys = [];
        }

        if (!in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            Cache::put($indexKey, $keys, now()->addSeconds(self::SEARCH_PATHS_CACHE_TTL_SECONDS * 2));
        }
    }

    private function invalidateSearchPathsCache(Server $server): void
    {
        $indexKey = $this->searchPathsIndexCacheKey($server);
        $keys = Cache::get($indexKey, []);
        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                Cache::forget($key);
            }
        }

        Cache::forget($indexKey);
    }

    /**
     * @return array<int, string>
     */
    private function pathCaseVariants(string $relativePath): array
    {
        $segments = explode('/', trim($relativePath, '/'));
        $variants = [$relativePath];
        foreach ($segments as $i => $seg) {
            $lower = strtolower($seg);
            $ucfirst = $lower !== '' ? ucfirst($lower) : $seg;
            foreach ([$lower, $ucfirst] as $v) {
                if ($v === $seg) {
                    continue;
                }
                $parts = $segments;
                $parts[$i] = $v;
                $variants[] = implode('/', $parts);
            }
        }

        return array_values(array_unique($variants));
    }

    private function isSearchableNoExtName(string $name): bool
    {
        foreach (self::SEARCHABLE_NO_EXT_NAMES as $pattern) {
            if (strcasecmp($name, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generates a one-time token with a link that the user can use to
     * download a given file.
     *
     * @throws \Throwable
     */
    public function download(GetFileContentsRequest $request, Server $server): array
    {
        $token = $this->jwtService
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setUser($request->user())
            ->setClaims([
                'file_path' => rawurldecode($request->get('file')),
                'server_uuid' => $server->uuid,
            ])
            ->handle($server->node, $request->user()->id . $server->uuid);

        Activity::event('server:file.download')->property('file', $request->get('file'))->log();

        return [
            'object' => 'signed_url',
            'attributes' => [
                'url' => sprintf(
                    '%s/download/file?token=%s',
                    $server->node->getConnectionAddress(),
                    $token->toString()
                ),
            ],
        ];
    }

    /**
     * Writes the contents of the specified file to the server.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function write(WriteFileContentRequest $request, Server $server): JsonResponse
    {
        $this->fileRepository->setServer($server)->putContent($request->get('file'), $request->getContent());
        $this->invalidateSearchPathsCache($server);

        Activity::event('server:file.write')->property('file', $request->get('file'))->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Creates a new folder on the server.
     *
     * @throws \Throwable
     */
    public function create(CreateFolderRequest $request, Server $server): JsonResponse
    {
        $this->fileRepository
            ->setServer($server)
            ->createDirectory($request->input('name'), $request->input('root', '/'));
        $this->invalidateSearchPathsCache($server);

        Activity::event('server:file.create-directory')
            ->property('name', $request->input('name'))
            ->property('directory', $request->input('root'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Renames a file on the remote machine.
     *
     * @throws \Throwable
     */
    public function rename(RenameFileRequest $request, Server $server): JsonResponse
    {
        $this->fileRepository
            ->setServer($server)
            ->renameFiles($request->input('root'), $request->input('files'));
        $this->invalidateSearchPathsCache($server);

        Activity::event('server:file.rename')
            ->property('directory', $request->input('root'))
            ->property('files', $request->input('files'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Copies a file on the server.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function copy(CopyFileRequest $request, Server $server): JsonResponse
    {
        $this->fileRepository
            ->setServer($server)
            ->copyFile($request->input('location'));
        $this->invalidateSearchPathsCache($server);

        Activity::event('server:file.copy')->property('file', $request->input('location'))->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function compress(CompressFilesRequest $request, Server $server): array
    {
        $file = $this->fileRepository->setServer($server)->compressFiles(
            $request->input('root'),
            $request->input('files')
        );
        $this->invalidateSearchPathsCache($server);

        Activity::event('server:file.compress')
            ->property('directory', $request->input('root'))
            ->property('files', $request->input('files'))
            ->log();

        return $this->fractal->item($file)
            ->transformWith($this->getTransformer(FileObjectTransformer::class))
            ->toArray();
    }

    /**
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function decompress(DecompressFilesRequest $request, Server $server): JsonResponse
    {
        set_time_limit(300);

        $this->fileRepository->setServer($server)->decompressFile(
            $request->input('root'),
            $request->input('file')
        );
        $this->invalidateSearchPathsCache($server);

        Activity::event('server:file.decompress')
            ->property('directory', $request->input('root'))
            ->property('files', $request->input('file'))
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Deletes files or folders for the server in the given root directory.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function delete(DeleteFileRequest $request, Server $server): JsonResponse
    {
        $this->fileRepository->setServer($server)->deleteFiles(
            $request->input('root'),
            $request->input('files')
        );
        $this->invalidateSearchPathsCache($server);

        Activity::event('server:file.delete')
            ->property('directory', $request->input('root'))
            ->property('files', $request->input('files'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Updates file permissions for file(s) in the given root directory.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function chmod(ChmodFilesRequest $request, Server $server): JsonResponse
    {
        $this->fileRepository->setServer($server)->chmodFiles(
            $request->input('root'),
            $request->input('files')
        );

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Requests that a file be downloaded from a remote location by Wings.
     *
     * @throws \Throwable
     */
    public function pull(PullFileRequest $request, Server $server): JsonResponse
    {
        $this->fileRepository->setServer($server)->pull(
            $request->input('url'),
            $request->input('directory'),
            $request->safe(['filename', 'use_header', 'foreground'])
        );
        $this->invalidateSearchPathsCache($server);

        Activity::event('server:file.pull')
            ->property('directory', $request->input('directory'))
            ->property('url', $request->input('url'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
