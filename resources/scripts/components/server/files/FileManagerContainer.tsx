import React, { useEffect, useState } from 'react';
import { httpErrorToHuman } from '@/api/http';
import { CSSTransition } from 'react-transition-group';
import Spinner from '@/components/elements/Spinner';
import FileObjectRow from '@/components/server/files/FileObjectRow';
import FileManagerBreadcrumbs from '@/components/server/files/FileManagerBreadcrumbs';
import { FileObject } from '@/api/server/files/loadDirectory';
import NewDirectoryButton from '@/components/server/files/NewDirectoryButton';
import { NavLink, useLocation, useHistory } from 'react-router-dom';
import Can from '@/components/elements/Can';
import { ServerError } from '@/components/elements/ScreenBlock';
import tw from 'twin.macro';
import debounce from 'debounce';
import { Button } from '@/components/elements/button/index';
import { ServerContext } from '@/state/server';
import useFileManagerSwr from '@/plugins/useFileManagerSwr';
import FileManagerStatus from '@/components/server/files/FileManagerStatus';
import MassActionsBar from '@/components/server/files/MassActionsBar';
import UploadButton from '@/components/server/files/UploadButton';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import { useStoreActions } from '@/state/hooks';
import ErrorBoundary from '@/components/elements/ErrorBoundary';
import { FileActionCheckbox } from '@/components/server/files/SelectFileCheckbox';
import searchFiles from '@/api/server/files/searchFiles';
import type { FileSearchHit } from '@/api/server/files/searchFiles';
import { hashToPath, encodePathSegments } from '@/helpers';
import style from './style.module.css';

import BeforeContent from '@blueprint/components/Server/Files/Browse/BeforeContent';
import FileButtons from '@blueprint/components/Server/Files/Browse/FileButtons';
import AfterContent from '@blueprint/components/Server/Files/Browse/AfterContent';

const sortFiles = (files: FileObject[]): FileObject[] => {
    const sortedFiles: FileObject[] = files
        .sort((a, b) => a.name.localeCompare(b.name))
        .sort((a, b) => (a.isFile === b.isFile ? 0 : a.isFile ? 1 : -1));
    return sortedFiles.filter((file, index) => index === 0 || file.name !== sortedFiles[index - 1].name);
};

const MIN_SEARCH_LEN = 2;

export default () => {
    const id = ServerContext.useStoreState((state) => state.server.data!.id);
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { hash } = useLocation();
    const history = useHistory();
    const { data: files, error, mutate } = useFileManagerSwr();
    const directory = ServerContext.useStoreState((state) => state.files.directory);
    const clearFlashes = useStoreActions((actions) => actions.flashes.clearFlashes);
    const setDirectory = ServerContext.useStoreActions((actions) => actions.files.setDirectory);

    const setSelectedFiles = ServerContext.useStoreActions((actions) => actions.files.setSelectedFiles);
    const selectedFilesLength = ServerContext.useStoreState((state) => state.files.selectedFiles.length);

    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState<FileSearchHit[] | null>(null);
    const [searchLoading, setSearchLoading] = useState(false);

    useEffect(() => {
        clearFlashes('files');
        setSelectedFiles([]);
        setDirectory(hashToPath(hash));
    }, [hash]);

    useEffect(() => {
        mutate();
    }, [directory]);

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
            searchFiles(uuid, directory || '/', q)
                .then(setSearchResults)
                .catch(() => setSearchResults([]))
                .finally(() => setSearchLoading(false));
        }, 350);
        run();
        return () => {
            run.clear?.();
        };
    }, [searchQuery, uuid, directory]);

    const onSelectAllClick = (e: React.ChangeEvent<HTMLInputElement>) => {
        setSelectedFiles(e.currentTarget.checked ? files?.map((file) => file.name) || [] : []);
    };

    const showContentSearch = searchQuery.trim().length >= MIN_SEARCH_LEN;

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => mutate()} />;
    }

    return (
        <ServerContentBlock title={'File Manager'} showFlashKey={'files'}>
            <ErrorBoundary>
                <BeforeContent />
                <Can action={'file.read-content'}>
                    <div css={tw`mb-3`}>
                        <input
                            type="search"
                            placeholder="Поиск по содержимому (текущая папка и вложенные)…"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            css={tw`w-full max-w-md rounded bg-neutral-700 border border-neutral-600 text-neutral-200 placeholder-neutral-500 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-transparent`}
                            aria-label="Поиск по содержимому файлов"
                        />
                    </div>
                </Can>
                <div className={'flex flex-wrap-reverse md:flex-nowrap mb-4'}>
                    <FileManagerBreadcrumbs
                        renderLeft={
                            <FileActionCheckbox
                                type={'checkbox'}
                                css={tw`mx-4`}
                                checked={selectedFilesLength === (files?.length === 0 ? -1 : files?.length)}
                                onChange={onSelectAllClick}
                            />
                        }
                    />
                    <Can action={'file.create'}>
                        <div className={style.manager_actions}>
                            <FileManagerStatus />
                            <FileButtons />
                            <NewDirectoryButton />
                            <UploadButton />
                            <NavLink to={`/server/${id}/files/new${window.location.hash}`}>
                                <Button>New File</Button>
                            </NavLink>
                        </div>
                    </Can>
                </div>
            </ErrorBoundary>
            {showContentSearch ? (
                <div css={tw`mb-4`}>
                    <p css={tw`text-neutral-400 text-sm mb-2`}>Результаты поиска</p>
                    {searchLoading ? (
                        <Spinner size="base" />
                    ) : searchResults && searchResults.length > 0 ? (
                        <ul css={tw`space-y-2`}>
                            {searchResults.map((hit) => (
                                <li key={hit.path}>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            history.push(`/server/${id}/files/edit#/${encodePathSegments(hit.path)}`)
                                        }
                                        css={tw`text-left w-full block py-2 px-3 rounded hover:bg-neutral-700 border border-neutral-600 text-neutral-200 transition-colors`}
                                    >
                                        <span css={tw`text-cyan-400 text-sm font-medium block truncate`}>{hit.path}</span>
                                        <span css={tw`text-neutral-500 text-xs block mt-0.5 line-clamp-2`}>
                                            {hit.snippet}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : searchResults && searchResults.length === 0 ? (
                        <p css={tw`text-neutral-500 text-sm`}>Ничего не найдено</p>
                    ) : null}
                </div>
            ) : !files ? (
                <Spinner size={'large'} centered />
            ) : (
                <>
                    {!files.length ? (
                        <p css={tw`text-sm text-neutral-400 text-center`}>This directory seems to be empty.</p>
                    ) : (
                        <CSSTransition classNames={'fade'} timeout={150} appear in>
                            <div>
                                {files.length > 250 && (
                                    <div css={tw`rounded bg-yellow-400 mb-px p-3`}>
                                        <p css={tw`text-yellow-900 text-sm text-center`}>
                                            This directory is too large to display in the browser, limiting the output
                                            to the first 250 files.
                                        </p>
                                    </div>
                                )}
                                {sortFiles(files.slice(0, 250)).map((file) => (
                                    <FileObjectRow key={file.key} file={file} />
                                ))}
                                <MassActionsBar />
                            </div>
                        </CSSTransition>
                    )}
                </>
            )}
            <AfterContent />
        </ServerContentBlock>
    );
};
