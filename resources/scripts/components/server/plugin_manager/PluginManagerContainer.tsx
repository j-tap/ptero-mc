import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ServerContext } from '@/state/server';
import FlashMessageRender from '@/components/FlashMessageRender';
import tw from 'twin.macro';
import Fade from '@/components/elements/Fade';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import { installPlugin, uninstallPlugin, togglePluginDisabled } from '@/api/server/plugin';
import Input from '@/components/elements/Input';
import Spinner from '@/components/elements/Spinner';
import { Button as OButton } from '@/components/elements/button/index';
import { Download, ExternalLink, Trash2, FolderOpen, Folder, Power, PowerOff, X } from 'lucide-react';
import PaginationFooter from '@/components/elements/table/PaginationFooter';
import usePlugins from '@/plugins/usePlugins';
import useInstalledPlugins from '@/plugins/useInstalledPlugins';
import { enrichInstalledPlugins } from '@/helpers/enrich-installed-plugins';
import { encodePathSegments } from '@/helpers';
import Tooltip from '@/components/elements/tooltip/Tooltip';

const PluginManagerContainer = () => {
    const [query, setQuery] = useState('');
    const [installedQuery, setInstalledQuery] = useState('');
    const [page, setPage] = useState(1);
    const [tab, setTab] = useState<'installed' | 'marketplace'>('installed');
    const [togglingFilename, setTogglingFilename] = useState<string | null>(null);

    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const serverId = ServerContext.useStoreState((state) => state.server.data!.id);
    const { data: plugins, pagination, loading, mutate: pluginMutate } = usePlugins({ uuid, query, page });
    const { data: installedPlugins = [], mutate: installedMutate } = useInstalledPlugins(uuid);

    const handleInstall = async (pluginId: number, pluginName: string) => {
        await installPlugin({ uuid, pluginId, pluginName });
        pluginMutate();
        installedMutate();
    };

    const handleUninstall = async (pluginId: number) => {
        await uninstallPlugin(uuid, pluginId);
        pluginMutate();
        installedMutate();
    };

    const handleToggleDisabled = async (filename: string) => {
        setTogglingFilename(filename);
        try {
            await togglePluginDisabled(uuid, filename);
            installedMutate();
        } finally {
            setTogglingFilename(null);
        }
    };

    useEffect(() => {
        setPage(1);
    }, [query]);

    const enrichedInstalledPlugins = useMemo(() => {
        const enriched = enrichInstalledPlugins(installedPlugins, plugins);
        const q = installedQuery.trim().toLowerCase();
        if (!q) return enriched;
        return enriched.filter(
            (plugin) =>
                plugin.plugin_name.toLowerCase().includes(q) ||
                (plugin.tag ?? '').toLowerCase().includes(q) ||
                (plugin.description ?? '').toLowerCase().includes(q)
        );
    }, [installedPlugins, plugins, installedQuery]);

    const availablePlugins = plugins.filter(
        (plugin) => !installedPlugins.some((installed) => installed.plugin_id === plugin.id)
    );

    return (
        <ServerContentBlock title={'Plugin Manager'}>
            <FlashMessageRender byKey={'plugin_manager'} css={tw`mb-4`} />

            <Fade timeout={150}>
                <>
                    <div className="flex flex-wrap items-center gap-2 mb-4">
                        <div className="flex space-x-2">
                            <button
                                onClick={() => setTab('installed')}
                                className={`px-4 py-2 rounded ${
                                    tab === 'installed'
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-neutral-700 text-neutral-300'
                                }`}
                            >
                                Installed
                            </button>
                            <button
                                onClick={() => setTab('marketplace')}
                                className={`px-4 py-2 rounded ${
                                    tab === 'marketplace'
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-neutral-700 text-neutral-300'
                                }`}
                            >
                                Marketplace
                            </button>
                        </div>
                        <Link
                            to={`/server/${serverId}/files#plugins`}
                            className="inline-flex items-center gap-1.5 px-3 py-2 rounded bg-neutral-700 text-neutral-300 hover:bg-neutral-600 hover:text-white transition-colors text-sm"
                        >
                            <FolderOpen size={18} />
                            <span>Open plugins folder</span>
                        </Link>
                    </div>

                    {tab === 'installed' && (
                        <div className="mb-4 relative inline-block w-full max-w-md">
                            <Input
                                type="text"
                                value={installedQuery}
                                placeholder="Search by name or description..."
                                onChange={(e) => setInstalledQuery(e.target.value)}
                                className="pr-9"
                            />
                            {installedQuery && (
                                <button
                                    type="button"
                                    onClick={() => setInstalledQuery('')}
                                    className="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded text-neutral-400 hover:text-neutral-200 hover:bg-neutral-600 transition-colors"
                                    aria-label="Clear search"
                                >
                                    <X size={18} />
                                </button>
                            )}
                        </div>
                    )}

                    {tab === 'marketplace' && (
                        <div className="mb-4 relative inline-block w-full max-w-md">
                            <Input
                                type="text"
                                value={query}
                                placeholder="Search for plugins..."
                                onChange={(e) => setQuery(e.target.value.trim())}
                                className="pr-9"
                            />
                            {query && (
                                <button
                                    type="button"
                                    onClick={() => setQuery('')}
                                    className="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded text-neutral-400 hover:text-neutral-200 hover:bg-neutral-600 transition-colors"
                                    aria-label="Clear search"
                                >
                                    <X size={18} />
                                </button>
                            )}
                        </div>
                    )}

                    {loading && <Spinner size='large' centered />}

                    {/* Installed Plugins */}
                    {!loading && tab === 'installed' && (
                        <>
                            <h3 className='text-white text-xl font-bold mt-4 mb-2'>Installed Plugins</h3>

                            {enrichedInstalledPlugins.length === 0 && (
                                <p className='text-neutral-400 mb-4'>
                                    {installedQuery.trim() ? 'No plugins match the search.' : 'No plugins installed.'}
                                </p>
                            )}

                            <div className='grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4'>
                                {enrichedInstalledPlugins.map((plugin) => {
                                    const description = plugin.tag ?? plugin.description ?? '';
                                    const isToggling = togglingFilename === plugin.filename;

                                    return (
                                        <div
                                            key={plugin.filename}
                                            className={`border p-4 shadow-sm flex flex-col justify-between ${
                                                plugin.disabled
                                                    ? 'border-amber-600/50 bg-neutral-700/70 opacity-80'
                                                    : 'border-neutral-600 bg-neutral-700'
                                            }`}
                                        >
                                            <div>
                                                <div className='flex space-x-2'>
                                                    <img
                                                        src={`https://api.spiget.org/v2/resources/${plugin.plugin_id}/icon`}
                                                        alt={plugin.plugin_name}
                                                        className='w-10 h-10 rounded bg-neutral-800 object-cover flex-shrink-0'
                                                        onError={(e) => (e.currentTarget.style.display = 'none')}
                                                    />
                                                    <div className='min-w-0'>
                                                        <div className='text-white font-semibold'>{plugin.plugin_name}</div>
                                                        {plugin.disabled && (
                                                            <span className='text-amber-400 text-xs'>Disabled</span>
                                                        )}
                                                    </div>
                                                </div>

                                                {description && (
                                                    <p className='mt-2 text-neutral-400 text-sm line-clamp-2' title={description}>
                                                        {description}
                                                    </p>
                                                )}

                                                {plugin.testedVersions && plugin.testedVersions.length > 0 && (
                                                    <p className='text-sm mt-2'>
                                                        Tested versions:
                                                        <span className='font-semibold block'>
                                                            {plugin.testedVersions.join(', ')}
                                                        </span>
                                                    </p>
                                                )}
                                            </div>

                                            <div className='flex flex-wrap items-center gap-2 justify-end w-full mt-4'>
                                                {plugin.config_folder && (
                                                    <Tooltip content="Config folder" placement="top">
                                                        <Link
                                                            to={`/server/${serverId}/files#${encodePathSegments('plugins/' + plugin.config_folder)}`}
                                                            className="inline-flex items-center justify-center w-8 h-8 rounded bg-neutral-600 hover:bg-neutral-500 text-neutral-200 transition-colors"
                                                        >
                                                            <Folder size={18} />
                                                        </Link>
                                                    </Tooltip>
                                                )}
                                                <Tooltip content="Details" placement="top">
                                                    <a
                                                        target='_blank'
                                                        rel='noreferrer'
                                                        href={`https://www.spigotmc.org/resources/${plugin.plugin_id}`}
                                                        className="inline-flex items-center justify-center w-8 h-8 rounded bg-neutral-600 hover:bg-neutral-500 text-neutral-200 transition-colors"
                                                    >
                                                        <ExternalLink size={18} />
                                                    </a>
                                                </Tooltip>
                                                <Tooltip content={plugin.disabled ? 'Enable' : 'Disable'} placement="top">
                                                    <span className="inline-flex">
                                                        <OButton
                                                            size={OButton.Sizes.Small}
                                                            isSecondary
                                                            disabled={isToggling}
                                                            className="!p-0 !w-8 !h-8 !min-w-0"
                                                            onClick={() => handleToggleDisabled(plugin.filename)}
                                                        >
                                                            {isToggling ? (
                                                                <Spinner size='small' />
                                                            ) : plugin.disabled ? (
                                                                <Power size={18} />
                                                            ) : (
                                                                <PowerOff size={18} />
                                                            )}
                                                        </OButton>
                                                    </span>
                                                </Tooltip>
                                                <Tooltip content="Uninstall" placement="top">
                                                    <OButton
                                                        size={OButton.Sizes.Small}
                                                        className='!p-0 !w-8 !h-8 !min-w-0 !bg-red-500 hover:!bg-red-600 duration-200'
                                                        onClick={() => handleUninstall(plugin.plugin_id)}
                                                    >
                                                        <Trash2 size={18} />
                                                    </OButton>
                                                </Tooltip>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </>
                    )}

                    {/* Marketplace */}
                    {!loading && tab === 'marketplace' && (
                        <>
                            <h3 className='text-white text-xl font-bold mt-8 mb-2'>Available Plugins</h3>

                            {availablePlugins.length === 0 && (
                                <p className='text-center text-neutral-400 mt-6'>
                                    No available plugins.
                                </p>
                            )}

                            <div className='grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4'>
                                {availablePlugins.map((plugin) => (
                                    <div
                                        key={plugin.id}
                                        className='border border-neutral-600 p-4 shadow-sm bg-neutral-700 flex flex-col justify-between'
                                    >
                                        <div>
                                            <div className='flex space-x-2'>
                                                <img
                                                    src={`https://api.spiget.org/v2/resources/${plugin.id}/icon`}
                                                    alt={plugin.name}
                                                    className='w-10 h-10 rounded bg-neutral-800 object-cover flex-shrink-0'
                                                    onError={(e) => (e.currentTarget.style.display = 'none')}
                                                />
                                                <div className='min-w-0'>
                                                    <div className='text-white font-semibold'>{plugin.name}</div>
                                                    {plugin.tag && (
                                                        <p className='text-neutral-400 text-sm mt-0.5 line-clamp-2' title={plugin.tag}>
                                                            {plugin.tag}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        <div className='flex items-center gap-2 justify-end w-full mt-6'>
                                            <Tooltip content="Details" placement="top">
                                                <a
                                                    target='_blank'
                                                    rel='noreferrer'
                                                    href={`https://www.spigotmc.org/resources/${plugin.id}`}
                                                    className="inline-flex items-center justify-center w-8 h-8 rounded bg-neutral-600 hover:bg-neutral-500 text-neutral-200 transition-colors"
                                                >
                                                    <ExternalLink size={18} />
                                                </a>
                                            </Tooltip>
                                            {!plugin.external && (
                                                <Tooltip content="Install" placement="top">
                                                    <OButton
                                                        variant={OButton.Variants.Primary}
                                                        size={OButton.Sizes.Small}
                                                        onClick={() => handleInstall(plugin.id, plugin.name)}
                                                        className="!p-0 !w-8 !h-8 !min-w-0"
                                                    >
                                                        <Download size={18} />
                                                    </OButton>
                                                </Tooltip>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {pagination && pagination.totalPages > 1 && (
                                <PaginationFooter
                                    pagination={pagination}
                                    onPageSelect={(newPage) => setPage(newPage)}
                                />
                            )}
                        </>
                    )}
                </>
            </Fade>
        </ServerContentBlock>
    );
};

export default PluginManagerContainer;