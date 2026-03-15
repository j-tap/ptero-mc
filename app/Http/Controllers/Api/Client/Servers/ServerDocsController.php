<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Transformers\Api\Client\FileObjectTransformer;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Docs\ListDocsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Docs\GetDocContentsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Docs\SearchDocsRequest;

class ServerDocsController extends ClientApiController
{
    private const DOC_EXTENSIONS = ['md', 'markdown'];

    public function __construct(
        private DaemonFileRepository $fileRepository,
    ) {
        parent::__construct();
    }

    /**
     * List files and directories in the docs folder (only .md/.markdown and dirs).
     */
    public function list(ListDocsRequest $request, Server $server): array
    {
        $base = trim(config('pterodactyl.files.docs_path', 'docs'), '/');
        $dir = (string) $request->input('directory', '');
        $path = $dir === '' ? $base : $base . '/' . $dir;

        $contents = $this->fileRepository
            ->setServer($server)
            ->getDirectory($path);

        $filtered = array_filter($contents, function (array $item) {
            $isDir = !Arr::get($item, 'file', true);
            if ($isDir) {
                return true;
            }
            $ext = strtolower(pathinfo(Arr::get($item, 'name', ''), PATHINFO_EXTENSION));
            return in_array($ext, self::DOC_EXTENSIONS, true);
        });

        return $this->fractal->collection(array_values($filtered))
            ->transformWith($this->getTransformer(FileObjectTransformer::class))
            ->toArray();
    }

    /**
     * Return the contents of a single .md file.
     */
    public function contents(GetDocContentsRequest $request, Server $server): Response
    {
        $base = trim(config('pterodactyl.files.docs_path', 'docs'), '/');
        $relative = trim(str_replace('\\', '/', $request->input('file')), '/');
        $path = $relative === '' ? $base : $base . '/' . $relative;
        $content = $this->fileRepository
            ->setServer($server)
            ->getContent($path, config('pterodactyl.files.max_edit_size'));

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * Search in docs by content. Returns matching file paths and snippets.
     */
    public function search(SearchDocsRequest $request, Server $server): array
    {
        $base = trim(config('pterodactyl.files.docs_path', 'docs'), '/');
        $q = $request->input('q');
        $repo = $this->fileRepository->setServer($server);
        $maxFiles = 60;
        $snippetLen = 120;

        $paths = [];
        $root = $repo->getDirectory($base);
        foreach ($root as $item) {
            $name = Arr::get($item, 'name', '');
            $isFile = Arr::get($item, 'file', true);
            if ($isFile) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, self::DOC_EXTENSIONS, true)) {
                    $paths[] = $name;
                }
            } else {
                try {
                    $sub = $repo->getDirectory($base . '/' . $name);
                    foreach ($sub as $subItem) {
                        $subName = Arr::get($subItem, 'name', '');
                        $subIsFile = Arr::get($subItem, 'file', true);
                        if ($subIsFile) {
                            $ext = strtolower(pathinfo($subName, PATHINFO_EXTENSION));
                            if (in_array($ext, self::DOC_EXTENSIONS, true)) {
                                $paths[] = $name . '/' . $subName;
                            }
                        }
                        if (count($paths) >= $maxFiles) {
                            break 2;
                        }
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
            if (count($paths) >= $maxFiles) {
                break;
            }
        }

        $results = [];
        foreach ($paths as $relative) {
            try {
                $fullPath = $base . '/' . $relative;
                $content = $repo->getContent($fullPath, config('pterodactyl.files.max_edit_size'));
                $pos = mb_stripos($content, $q);
                if ($pos === false) {
                    continue;
                }
                $start = max(0, $pos - (int) ($snippetLen / 2));
                $snippet = mb_substr($content, $start, $snippetLen);
                $snippet = preg_replace('/\s+/', ' ', trim($snippet));
                if (mb_strlen($snippet) > $snippetLen) {
                    $snippet = mb_substr($snippet, 0, $snippetLen - 1) . '…';
                }
                $results[] = [
                    'path' => $relative,
                    'snippet' => $snippet,
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }

        return [
            'object' => 'list',
            'data' => array_values($results),
        ];
    }
}
