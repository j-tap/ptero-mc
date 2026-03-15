import 'github-markdown-css/github-markdown-dark.css';
import './docs.overrides.css';
import React, { useEffect, useState } from 'react';
import { useHistory, useLocation } from 'react-router-dom';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import debounce from 'debounce';
import tw from 'twin.macro';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { ServerError } from '@/components/elements/ScreenBlock';
import { httpErrorToHuman } from '@/api/http';
import useDocsListSwr from '@/plugins/useDocsListSwr';
import useDocContentSwr from '@/plugins/useDocContentSwr';
import searchDocs from '@/api/server/docs/searchDocs';
import type { DocSearchHit } from '@/api/server/docs/searchDocs';
import type { FileObject } from '@/api/server/files/loadDirectory';
import { ServerContext } from '@/state/server';

const DOC_EXT = /\.(md|markdown)$/i;

/** Путь вида "server.md/index.md" невалиден (файл как папка). Нормализуем в "index.md". */
function normalizeDocPath(path: string): string {
    if (!path || !path.includes('/')) return path;
    const segments = path.split('/');
    const first = segments[0];
    if (DOC_EXT.test(first)) return segments.slice(1).join('/') || path;
    return path;
}

function resolveDocPath(basePath: string, href: string): string {
    const baseDir = basePath.replace(/\/[^/]+$/, '') || '';
    const baseUrl = 'http://dummy/' + (baseDir ? baseDir + '/' : '');
    try {
        const resolved = new URL(href, baseUrl).pathname.replace(/^\/+/, '');
        return normalizeDocPath(resolved);
    } catch {
        return href;
    }
}

function DocList({
    directory,
    files,
    selectedFile,
    onSelectFile,
    onSelectDir,
    onBreadcrumb,
}: {
    directory: string;
    files: FileObject[];
    selectedFile: string | null;
    onSelectFile: (path: string) => void;
    onSelectDir: (path: string) => void;
    onBreadcrumb: (path: string) => void;
}) {
    const dirs = files.filter((f) => !f.isFile).sort((a, b) => a.name.localeCompare(b.name));
    const docs = files.filter((f) => f.isFile && DOC_EXT.test(f.name)).sort((a, b) => a.name.localeCompare(b.name));
    const parts = directory ? directory.split('/') : [];

    return (
        <div css={tw`mb-4`}>
            <div css={tw`flex flex-wrap items-center gap-2 text-base text-neutral-400 mb-3`}>
                <button type="button" onClick={() => onBreadcrumb('')} css={tw`hover:text-neutral-300 font-medium`}>
                    Docs
                </button>
                {parts.map((part, i) => {
                    const path = parts.slice(0, i + 1).join('/');
                    return (
                        <span key={path}>
                            <span css={tw`mx-1`}>/</span>
                            <button type="button" onClick={() => onBreadcrumb(path)} css={tw`hover:text-neutral-300 font-medium`}>
                                {part}
                            </button>
                        </span>
                    );
                })}
            </div>
            <ul css={tw`space-y-2`}>
                {dirs.map((d) => (
                    <li key={d.key}>
                        <button
                            type="button"
                            onClick={() => onSelectDir(directory ? `${directory}/${d.name}` : d.name)}
                            css={tw`text-left text-base text-cyan-400 hover:underline flex items-center gap-2 py-1 w-full`}
                        >
                            <span aria-hidden>📁</span> {d.name}
                        </button>
                    </li>
                ))}
                {docs.map((f) => {
                    const path = directory ? `${directory}/${f.name}` : f.name;
                    const active = selectedFile === path;
                    return (
                        <li key={f.key}>
                            <button
                                type="button"
                                onClick={() => onSelectFile(path)}
                                css={[
                                    tw`text-left text-base hover:underline flex items-center gap-2 py-1 w-full`,
                                    active ? tw`text-cyan-300 font-medium` : tw`text-neutral-300`,
                                ]}
                            >
                                <span aria-hidden>📄</span> {f.name}
                            </button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function DocView({ file, currentPath, onNavigate }: { file: string; currentPath: string; onNavigate: (path: string) => void }) {
    const { data: content, error, mutate } = useDocContentSwr(file);

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => mutate()} />;
    }
    if (content === undefined) {
        return <Spinner size="large" centered />;
    }

    const baseDir = currentPath.replace(/\/[^/]+$/, '') || '';

    return (
        <div className="docsContent">
            <div className="markdown-body" style={{ backgroundColor: 'transparent', padding: 0 }}>
                <ReactMarkdown
                source={content}
                plugins={[remarkGfm]}
                renderers={{
                    link: ({ href, children }: { href?: string; children: React.ReactNode }) => {
                        if (!href || href.startsWith('http://') || href.startsWith('https://') || href.startsWith('#')) {
                            return <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>;
                        }
                        let resolved = resolveDocPath(baseDir, href);
                        resolved = normalizeDocPath(resolved);
                        if (resolved && DOC_EXT.test(resolved)) {
                            return (
                                <a
                                    href="#"
                                    onClick={(e) => {
                                        e.preventDefault();
                                        onNavigate(resolved);
                                    }}
                                >
                                    {children}
                                </a>
                            );
                        }
                        return <a href={href}>{children}</a>;
                    },
                }}
                />
            </div>
        </div>
    );
}

const MIN_SEARCH_LEN = 2;

export default () => {
    const { search } = useLocation();
    const history = useHistory();
    const params = new URLSearchParams(search);
    const dir = params.get('dir') ?? '';
    const rawFile = params.get('file');
    const file = rawFile ? normalizeDocPath(rawFile) || rawFile : rawFile;

    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState<DocSearchHit[] | null>(null);
    const [searchLoading, setSearchLoading] = useState(false);

    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const { data: files, error, mutate } = useDocsListSwr(dir);

    useEffect(() => {
        if (!rawFile) return;
        const normalized = normalizeDocPath(rawFile);
        if (normalized !== rawFile) {
            const p = new URLSearchParams(search);
            p.set('file', normalized);
            history.replace({ search: p.toString() });
        }
    }, [rawFile, search, history]);

    useEffect(() => {
        const q = searchQuery.trim();
        if (q.length < MIN_SEARCH_LEN) {
            setSearchResults(null);
            setSearchLoading(false);
            return;
        }
        const run = debounce(() => {
            if (!uuid) return;
            setSearchLoading(true);
            searchDocs(uuid, q)
                .then(setSearchResults)
                .catch(() => setSearchResults([]))
                .finally(() => setSearchLoading(false));
        }, 350);
        run();
        return () => {
            run.clear?.();
        };
    }, [searchQuery, uuid]);

    const setQuery = (next: { dir?: string; file?: string | null }) => {
        const p = new URLSearchParams(search);
        if (next.dir !== undefined) p.set('dir', next.dir);
        if (next.file !== undefined) (next.file ? p.set('file', next.file) : p.delete('file'));
        history.push({ search: p.toString() });
    };

    const showContentSearch = searchQuery.trim().length >= MIN_SEARCH_LEN;

    if (error) {
        return (
            <ServerContentBlock title="Documentation">
                <ServerError message={httpErrorToHuman(error)} onRetry={() => mutate()} />
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title="Documentation">
            <div css={tw`flex flex-col lg:flex-row gap-6`}>
                <div css={tw`lg:w-52 lg:flex-shrink-0 flex flex-col gap-3`}>
                    <input
                        type="search"
                        placeholder="Поиск по содержимому…"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        css={tw`w-full rounded bg-neutral-700 border border-neutral-600 text-neutral-200 placeholder-neutral-500 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-transparent`}
                        aria-label="Поиск по документации"
                    />
                    {showContentSearch ? (
                        <div>
                            <p css={tw`text-neutral-400 text-sm mb-2`}>Результаты поиска</p>
                            {searchLoading ? (
                                <Spinner size="base" />
                            ) : searchResults && searchResults.length > 0 ? (
                                <ul css={tw`space-y-2`}>
                                    {searchResults.map((hit) => (
                                        <li key={hit.path}>
                                            <button
                                                type="button"
                                                onClick={() => setQuery({ file: hit.path })}
                                                css={tw`text-left w-full block py-2 px-2 rounded hover:bg-neutral-700 border border-transparent hover:border-neutral-600 transition-colors`}
                                            >
                                                <span css={tw`text-cyan-400 text-sm font-medium block truncate`}>{hit.path}</span>
                                                <span css={tw`text-neutral-500 text-xs block mt-0.5 line-clamp-2`}>{hit.snippet}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            ) : searchResults && searchResults.length === 0 ? (
                                <p css={tw`text-neutral-500 text-sm`}>Ничего не найдено</p>
                            ) : null}
                        </div>
                    ) : files === undefined ? (
                        <Spinner size="base" />
                    ) : (
                        <DocList
                            directory={dir}
                            files={files}
                            selectedFile={file}
                            onSelectFile={(path) => setQuery({ file: path })}
                            onSelectDir={(path) => setQuery({ dir: path, file: null })}
                            onBreadcrumb={(path) => setQuery({ dir: path, file: null })}
                        />
                    )}
                </div>
                <div css={tw`min-w-0 flex-1`}>
                    {file ? (
                        <DocView
                            file={file}
                            currentPath={file}
                            onNavigate={(path) => setQuery({ file: path })}
                        />
                    ) : (
                        <p css={tw`text-neutral-500 text-sm`}>Выберите документ из списка.</p>
                    )}
                </div>
            </div>
        </ServerContentBlock>
    );
};
