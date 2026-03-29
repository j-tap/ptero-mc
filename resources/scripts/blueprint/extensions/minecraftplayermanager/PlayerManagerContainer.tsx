import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import useSWR, { mutate as mutateGlobal } from 'swr';
import getStatus, { Player } from './api/getStatus';
import { Button } from '@/components/elements/button/index';
import { Dialog } from '@/components/elements/dialog/index';
import useFlash from '@/plugins/useFlash';
import PlayerTable from './PlayerTable';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faBan,
    faBox,
    faCog,
    faCrown,
    faCross,
    faExclamationTriangle,
    faInfoCircle,
    faLocationArrow,
    faSkull,
    faSkullCrossbones,
    faTag,
    faTrash,
    faUserCheck,
    faUserClock,
    faUserCog,
    faUserPlus,
    faWineBottle,
} from '@fortawesome/free-solid-svg-icons';
import removeWhitelist from './api/removeWhitelist';
import addWhitelist from './api/addWhitelist';
import FlashMessageRender from '@/components/FlashMessageRender';
import op from './api/op';
import deop from './api/deop';
import Banner from './Banner';
import unban from './api/unban';
import ban from './api/ban';
import Label from '@/components/elements/Label';
import { Input } from '@/components/elements/inputs/index';
import kick from './api/kick';
import clear from './api/clear';
import wipe from './api/wipe';
import setWhitelistEnabled from './api/setWhitelistEnabled';
import unbanip from './api/unbanip';
import banip from './api/banip';
import banipPlayer from './api/banipPlayer';
import kill from './api/kill';
import getStats from './api/getStats';
import getInventory, { type InventoryItem } from './api/getInventory';
import setGamemode, { type Gamemode } from './api/setGamemode';
import setLevel from './api/setLevel';
import setPosition from './api/setPosition';
import UptimeDuration from '@/components/server/UptimeDuration';
import getOffline, { type OfflinePlayer } from './api/getOffline';
import Select from '@/components/elements/Select';
import OldInput from '@/components/elements/Input';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import removeInventoryItem from './api/removeInventoryItem';

type PlayerRow = OfflinePlayer & {
    reason?: string;
};

const INVENTORY_SLOT_ORDER: number[][] = [
    [100, 101, 102, 103, 40],
    [9, 10, 11, 12, 13, 14, 15, 16, 17],
    [18, 19, 20, 21, 22, 23, 24, 25, 26],
    [27, 28, 29, 30, 31, 32, 33, 34, 35],
    [0, 1, 2, 3, 4, 5, 6, 7, 8],
];

function formatItemId(id: string): string {
    const name = id.replace(/^minecraft:/, '').replace(/_/g, ' ');
    return name.replace(/\b\w/g, (c) => c.toUpperCase());
}

function normalizeUuid(value?: string | null): string {
    return (value ?? '').replace(/-/g, '').toLowerCase();
}

function isSamePlayerIdentity(
    left: { uuid?: string | null; name?: string | null },
    right: { uuid?: string | null; name?: string | null }
): boolean {
    const leftUuid = normalizeUuid(left.uuid);
    const rightUuid = normalizeUuid(right.uuid);

    if (leftUuid && rightUuid) {
        return leftUuid === rightUuid;
    }

    const leftName = (left.name ?? '').trim().toLowerCase();
    const rightName = (right.name ?? '').trim().toLowerCase();

    return leftName !== '' && leftName === rightName;
}

const ITEM_IMAGE_BASE = 'https://velithcraft.online/api/minecraft/items';

function itemIconUrl(id: string): string {
    const path = id.replace(/^minecraft:/, '');
    return `${ITEM_IMAGE_BASE}/${path}/image`;
}

const SLOT_LABELS: Record<number, string> = {
    100: 'Boots',
    101: 'Leggings',
    102: 'Chestplate',
    103: 'Helmet',
    40: 'Offhand',
};

function PlayerInventoryGrid({
    items,
    isLoading,
    onRemoveItem,
}: {
    items: InventoryItem[];
    isLoading: boolean;
    onRemoveItem: (item: InventoryItem) => Promise<void>;
}) {
    const bySlot = new Map<number, InventoryItem>();
    items.forEach((item) => bySlot.set(item.slot, item));

    return (
        <div className={'flex flex-col gap-2 w-fit max-w-full'}>
            <h2 className={'text-lg flex flex-row items-center mb-1'}>
                <FontAwesomeIcon icon={faBox} className={'mr-2'} />
                Inventory
            </h2>
            <div className={'flex flex-col gap-1 w-fit'}>
                {INVENTORY_SLOT_ORDER.map((row, rowIndex) => (
                    <div
                        key={rowIndex}
                        className={
                            row.length === 5
                                ? 'grid grid-cols-5 gap-1 w-fit'
                                : row.length === 10
                                    ? 'grid grid-cols-10 gap-1 w-fit'
                                    : 'grid grid-cols-9 gap-1 w-fit'
                        }
                    >
                        {row.map((slot) => {
                            const item = bySlot.get(slot);
                            const label = item
                                ? item.displayName ?? formatItemId(item.id)
                                : '';
                            const count = item?.count ?? 0;
                            const slotLabel = SLOT_LABELS[slot];
                            const tooltipContent = item
                                ? `${label}${count > 1 ? ` ×${count}` : ''}${slotLabel ? ` (${slotLabel})` : ''}`
                                : slotLabel
                                    ? `Slot ${slot} (${slotLabel})`
                                    : `Slot ${slot}`;
                            return (
                                <Tooltip key={slot} content={tooltipContent} placement={'top'}>
                                    <div
                                        className={
                                            'bg-neutral-700 rounded border border-neutral-600 flex items-center justify-center aspect-square w-11 min-w-0 p-1 relative cursor-default'
                                        }
                                    >
                                        {item ? (
                                            <>
                                                <button
                                                    type={'button'}
                                                    className={
                                                        'flex absolute top-0.5 right-0.5 text-red-400 hover:text-red-300 transition-colors disabled:opacity-50 disabled:cursor-not-allowed'
                                                    }
                                                    disabled={isLoading}
                                                    onClick={(event) => {
                                                        event.preventDefault();
                                                        event.stopPropagation();
                                                        void onRemoveItem(item);
                                                    }}
                                                    onMouseDown={(event) => {
                                                        event.preventDefault();
                                                        event.stopPropagation();
                                                    }}
                                                    title={'Delete item permanently'}
                                                >
                                                    <FontAwesomeIcon icon={faTrash} className={'text-[10px]'} />
                                                </button>
                                                <img
                                                    src={itemIconUrl(item.id)}
                                                    alt={label}
                                                    className={'w-full h-full max-w-full max-h-full object-contain flex-shrink-0'}
                                                    onError={(e) => {
                                                        (e.target as HTMLImageElement).style.display = 'none';
                                                    }}
                                                />
                                                {count > 1 && (
                                                    <span className={'absolute bottom-0 left-0.5 text-xs font-bold text-white drop-shadow'}>
                                                        {count}
                                                    </span>
                                                )}
                                            </>
                                        ) : null}
                                        <span className={'absolute bottom-0 right-0.5 text-[10px] text-neutral-400 font-medium tabular-nums'}>
                                            {slot}
                                        </span>
                                    </div>
                                </Tooltip>
                            );
                        })}
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function PlayerManagerContainer() {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const [isLoading, setIsLoading] = useState(false);
    const [search, setSearch] = useState('');
    const [limit, setLimit] = useState(50);
    const [successAuthOnly, setSuccessAuthOnly] = useState(true);
    const [tableFilter, setTableFilter] = useState<'all' | 'opped' | 'whitelisted' | 'banned'>('all');
    const [bannedIpsModalVisible, setBannedIpsModalVisible] = useState(false);
    const [player, setPlayer] = useState<Player>();
    const [playerPage, setPlayerPage] = useState<'actions' | 'stats' | 'inventory'>('actions');
    const [reason, setReason] = useState<string>('');
    const [address, setAddress] = useState<string>('');
    const [playerInput, setPlayerInput] = useState<string>('');
    const [confirmOp, setConfirmOp] = useState<Player>();
    const [confirmBan, setConfirmBan] = useState<Player>();
    const [confirmIpBan, setConfirmIpBan] = useState<Player>();
    const [confirmKick, setConfirmKick] = useState<Player>();
    const [confirmClear, setConfirmClear] = useState<Player>();
    const [confirmWipe, setConfirmWipe] = useState<Player>();
    const [confirmKill, setConfirmKill] = useState<Player>();
    const [confirmWhitelistEnabled, setConfirmWhitelistEnabled] = useState<boolean | null>(null);
    const [newOpModalVisible, setNewOpModalVisible] = useState(false);
    const [newWhitelistModalVisible, setNewWhitelistModalVisible] = useState(false);
    const [newBanModalVisible, setNewBanModalVisible] = useState(false);

    useEffect(() => {
        clearFlashes();
    }, [player, tableFilter]);

    const { data: query, mutate } = useSWR(['players', 'query', uuid], () => getStatus(uuid), {
        refreshInterval: 10000,
    });

    const { data: offline } = useSWR(['players', 'offline', uuid], () => getOffline(uuid), {
        refreshInterval: 10000,
    });

    const { data: stats } = useSWR(
        ['players', 'stats', uuid, player?.uuid],
        () => (player ? getStats(uuid, player.uuid) : undefined),
        { refreshInterval: 30000 }
    );

    const { data: inventoryItems } = useSWR<InventoryItem[]>(
        player && playerPage === 'inventory' ? ['players', 'inventory', uuid, player.uuid] : null,
        () => getInventory(uuid, player!.uuid),
        { revalidateOnFocus: false }
    );

    const onlinePlayerUuids = useMemo(() => {
        if (!query?.online) {
            return new Set<string>();
        }

        return new Set(query.players.list.map((nextPlayer) => normalizeUuid(nextPlayer.uuid)));
    }, [query]);

    const allPlayers = useMemo<PlayerRow[]>(() => {
        const merged = new Map<string, PlayerRow>();

        (offline?.players ?? []).forEach((nextPlayer) => {
            merged.set(normalizeUuid(nextPlayer.uuid), nextPlayer);
        });

        if (query?.online) {
            query.players.list.forEach((nextPlayer) => {
                const key = normalizeUuid(nextPlayer.uuid);
                const existing = merged.get(key);
                merged.set(key, {
                    ...existing,
                    ...nextPlayer,
                    reason:
                        query.banned.players.find((bannedPlayer) => isSamePlayerIdentity(bannedPlayer, nextPlayer))?.reason ??
                        existing?.reason,
                    playtime: existing?.playtime ?? null,
                    session_count: existing?.session_count ?? null,
                    has_session: existing?.has_session ?? null,
                    reg_ip: existing?.reg_ip ?? null,
                    ip: existing?.ip ?? null,
                    world: existing?.world ?? null,
                    first_seen_at: existing?.first_seen_at ?? null,
                    last_logout_at: existing?.last_logout_at ?? null,
                });
            });
        }

        return Array.from(merged.values());
    }, [offline?.players, query]);

    const offlineWarnings = useMemo(() => offline?.warnings ?? [], [offline?.warnings]);

    const filteredAllPlayers = useMemo(() => {
        const querySearch = search.toLowerCase();

        const byFilter = (nextPlayer: PlayerRow) => {
            if (tableFilter === 'opped') {
                return query?.opped ? query.opped.some((oppedPlayer) => isSamePlayerIdentity(oppedPlayer, nextPlayer)) : false;
            }
            if (tableFilter === 'whitelisted') {
                return query?.whitelist?.list
                    ? query.whitelist.list.some((whitelistedPlayer) => isSamePlayerIdentity(whitelistedPlayer, nextPlayer))
                    : false;
            }
            if (tableFilter === 'banned') {
                return query?.banned?.players
                    ? query.banned.players.some((bannedPlayer) => isSamePlayerIdentity(bannedPlayer, nextPlayer))
                    : false;
            }

            return true;
        };

        return allPlayers
            .filter((nextPlayer) => nextPlayer.name.toLowerCase().includes(querySearch))
            .filter(byFilter)
            .filter((nextPlayer) => (successAuthOnly ? Boolean(nextPlayer.first_seen_at) : true))
            .slice(0, limit);
    }, [allPlayers, limit, query, search, successAuthOnly, tableFilter]);

    const isPlayerOnline = useCallback(
        (nextPlayer: { uuid?: string | null; name?: string | null }) =>
            query?.online ? query.players.list.some((onlinePlayer) => isSamePlayerIdentity(onlinePlayer, nextPlayer)) : false,
        [query]
    );

    const isPlayerOpped = useCallback(
        (nextPlayer: { uuid?: string | null; name?: string | null }) =>
            query?.opped ? query.opped.some((oppedPlayer) => isSamePlayerIdentity(oppedPlayer, nextPlayer)) : false,
        [query]
    );

    const isPlayerWhitelisted = useCallback(
        (nextPlayer: { uuid?: string | null; name?: string | null }) =>
            query?.whitelist?.list
                ? query.whitelist.list.some((whitelistedPlayer) => isSamePlayerIdentity(whitelistedPlayer, nextPlayer))
                : false,
        [query]
    );

    const isPlayerBanned = useCallback(
        (nextPlayer: { uuid?: string | null; name?: string | null }) =>
            query?.banned?.players
                ? query.banned.players.some((bannedPlayer) => isSamePlayerIdentity(bannedPlayer, nextPlayer))
                : false,
        [query]
    );

    const selectedPlayerIsOnline = useMemo(() => (player ? isPlayerOnline(player) : false), [isPlayerOnline, player]);

    const applyWhitelistEnabled = (nextEnabled: boolean) => {
        if (!query) {
            return;
        }

        setIsLoading(true);

        setWhitelistEnabled(uuid, nextEnabled)
            .then(() =>
                mutate(
                    {
                        ...query,
                        whitelist: {
                            ...query.whitelist,
                            enabled: nextEnabled,
                        },
                    },
                    false
                )
            )
            .catch((error) => {
                console.error(error);
                clearAndAddHttpError({ error, key: 'players:view' });
            })
            .finally(() => setIsLoading(false));
    };

    if (!query) {
        return (
            <ServerContentBlock title={'Players'}>
                <Spinner size={'large'} centered />
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title={'Players'}>
            <Dialog.Confirm
                open={confirmWhitelistEnabled !== null}
                onClose={() => setConfirmWhitelistEnabled(null)}
                onConfirmed={() => {
                    if (isLoading || confirmWhitelistEnabled === null) return;

                    applyWhitelistEnabled(confirmWhitelistEnabled);
                    setConfirmWhitelistEnabled(null);
                }}
                confirm={'Confirm'}
            >
                <Banner title={'Warning'} className={'bg-red-600 mt-10'} icon={<FontAwesomeIcon icon={faExclamationTriangle} />}>
                    Enabling whitelist means only players present in the whitelist will be able to join the server.
                </Banner>
                <p className={'mt-2'}>
                    Are you sure you want to enable whitelist on this server now?
                </p>
            </Dialog.Confirm>
            <Dialog.Confirm
                open={Boolean(confirmOp)}
                onClose={() => {
                    setPlayer(confirmOp);
                    setConfirmOp(undefined);
                }}
                onConfirmed={() => {
                    if (isLoading) return;
                    setIsLoading(true);

                    op(uuid, confirmOp!.name)
                        .then(() =>
                            mutate(
                                {
                                    ...query,
                                    opped: [...query.opped, { ...confirmOp!, bypassesPlayerLimit: true, level: 4 }],
                                },
                                false
                            )
                        )
                        .catch((error) => {
                            console.error(error);
                            clearAndAddHttpError({ error, key: 'players:view' });
                        })
                        .finally(() => {
                            setIsLoading(false);
                            setPlayer(confirmOp);
                            setConfirmOp(undefined);
                        });
                }}
                confirm={'Confirm'}
            >
                {confirmOp && (
                    <>
                        <div className={'z-50 left-6 top-4 absolute h-8 flex flex-row items-center'}>
                            <img src={confirmOp.avatar} alt={confirmOp.name} className={'w-8 h-8 rounded-md'} />
                            <span className={'ml-2 flex flex-col justify-center'}>
                                <h1 className={'text-lg'}>{confirmOp.name}</h1>
                                <p className={'-mt-2 text-sm text-neutral-400'}>
                                    {isPlayerOnline(confirmOp)
                                        ? 'Online'
                                        : 'Offline'}
                                </p>
                            </span>
                        </div>
                        <Banner
                            title={'Warning'}
                            className={'bg-red-600 mt-10'}
                            icon={<FontAwesomeIcon icon={faExclamationTriangle} />}
                        >
                            Players with OP can do all sorts of (potentially) bad things to your server and give other
                            players OP. Only give OP to people you trust.
                        </Banner>
                        <p className={'mt-2'}>
                            Are you sure you want to give <strong>{confirmOp.name}</strong> OP on this server?
                        </p>
                    </>
                )}
            </Dialog.Confirm>
            <Dialog.Confirm
                open={Boolean(confirmBan)}
                onClose={() => setConfirmBan(undefined)}
                onConfirmed={() => {
                    if (isLoading || reason.length < 3 || reason.length > 255) return;
                    setIsLoading(true);

                    ban(uuid, confirmBan!.name, reason)
                        .then(() =>
                            mutate(
                                {
                                    ...query,
                                    banned: {
                                        ...query.banned,
                                        players: [...query.banned.players, { ...confirmBan!, reason }],
                                    },
                                },
                                false
                            )
                        )
                        .then(() => setReason(''))
                        .catch((error) => {
                            console.error(error);
                            clearAndAddHttpError({ error, key: 'players:view' });
                            setPlayer(confirmBan);
                        })
                        .finally(() => {
                            setIsLoading(false);
                            setConfirmBan(undefined);
                        });
                }}
                confirm={'Confirm'}
            >
                {confirmBan && (
                    <>
                        <div className={'z-50 left-6 top-4 absolute h-8 flex flex-row items-center'}>
                            <img src={confirmBan.avatar} alt={confirmBan.name} className={'w-8 h-8 rounded-md'} />
                            <span className={'ml-2 flex flex-col justify-center'}>
                                <h1 className={'text-lg'}>{confirmBan.name}</h1>
                                <p className={'-mt-2 text-sm text-neutral-400'}>
                                    {isPlayerOnline(confirmBan)
                                        ? 'Online'
                                        : 'Offline'}
                                </p>
                            </span>
                        </div>
                        <Banner
                            title={'Information'}
                            className={'bg-blue-600 mt-10'}
                            icon={<FontAwesomeIcon icon={faInfoCircle} />}
                        >
                            Banning a player will no longer make them able to join this server until they are unbanned.
                            This ban is permanent until you remove it.
                        </Banner>
                        <p className={'mt-2'}>
                            Are you sure you want to ban <strong>{confirmBan.name}</strong> from this server?
                        </p>

                        <Label className={'mt-6'}>Reason</Label>
                        <Input.Text
                            placeholder={'Griefing'}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                    </>
                )}
            </Dialog.Confirm>
            <Dialog.Confirm
                open={Boolean(confirmKick)}
                onClose={() => setConfirmKick(undefined)}
                onConfirmed={() => {
                    if (isLoading || reason.length < 3 || reason.length > 255) return;
                    setIsLoading(true);

                    kick(uuid, confirmKick!.uuid, reason)
                        .then(() =>
                            mutate(
                                {
                                    ...query,
                                    banned: {
                                        ...query.banned,
                                        players: [...query.banned.players, { ...confirmKick!, reason }],
                                    },
                                },
                                false
                            )
                        )
                        .then(() => setReason(''))
                        .catch((error) => {
                            console.error(error);
                            clearAndAddHttpError({ error, key: 'players:view' });
                            setPlayer(confirmKick);
                        })
                        .finally(() => {
                            setIsLoading(false);
                            setConfirmKick(undefined);
                        });
                }}
                confirm={'Confirm'}
            >
                {confirmKick && (
                    <>
                        <div className={'z-50 left-6 top-4 absolute h-8 flex flex-row items-center'}>
                            <img src={confirmKick.avatar} alt={confirmKick.name} className={'w-8 h-8 rounded-md'} />
                            <span className={'ml-2 flex flex-col justify-center'}>
                                <h1 className={'text-lg'}>{confirmKick.name}</h1>
                                <p className={'-mt-2 text-sm text-neutral-400'}>
                                    {isPlayerOnline(confirmKick)
                                        ? 'Online'
                                        : 'Offline'}
                                </p>
                            </span>
                        </div>
                        <Banner
                            title={'Information'}
                            className={'bg-blue-600 mt-10'}
                            icon={<FontAwesomeIcon icon={faInfoCircle} />}
                        >
                            Kicking a player will remove them from the server immediately. They will be able to rejoin
                            unless they are banned.
                        </Banner>
                        <p className={'mt-2'}>
                            Are you sure you want to kick <strong>{confirmKick.name}</strong> from this server?
                        </p>

                        <Label className={'mt-6'}>Reason</Label>
                        <Input.Text
                            placeholder={'Griefing'}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                    </>
                )}
            </Dialog.Confirm>
            <Dialog.Confirm
                open={Boolean(confirmClear)}
                onClose={() => {
                    setPlayer(confirmClear);
                    setConfirmClear(undefined);
                }}
                onConfirmed={() => {
                    if (isLoading) return;
                    setIsLoading(true);

                    clear(uuid, confirmClear!.uuid)
                        .catch((error) => {
                            console.error(error);
                            clearAndAddHttpError({ error, key: 'players:view' });
                        })
                        .finally(() => {
                            setIsLoading(false);
                            setPlayer(confirmClear);
                            setConfirmClear(undefined);
                        });
                }}
                confirm={'Confirm'}
            >
                {confirmClear && (
                    <>
                        <div className={'z-50 left-6 top-4 absolute h-8 flex flex-row items-center'}>
                            <img src={confirmClear.avatar} alt={confirmClear.name} className={'w-8 h-8 rounded-md'} />
                            <span className={'ml-2 flex flex-col justify-center'}>
                                <h1 className={'text-lg'}>{confirmClear.name}</h1>
                                <p className={'-mt-2 text-sm text-neutral-400'}>
                                    {isPlayerOnline(confirmClear)
                                        ? 'Online'
                                        : 'Offline'}
                                </p>
                            </span>
                        </div>
                        <Banner
                            title={'Warning'}
                            className={'bg-red-600 mt-10'}
                            icon={<FontAwesomeIcon icon={faExclamationTriangle} />}
                        >
                            Clearing a player&apos;s inventory will cause them to lose all of their items permanently. This
                            action cannot be undone.
                        </Banner>
                        <p className={'mt-2'}>
                            Are you sure you want to clear the inventory of <strong>{confirmClear.name}</strong> on this
                            server?
                        </p>
                    </>
                )}
            </Dialog.Confirm>
            <Dialog.Confirm
                open={Boolean(confirmWipe)}
                onClose={() => {
                    setPlayer(confirmWipe);
                    setConfirmWipe(undefined);
                }}
                onConfirmed={() => {
                    if (isLoading) return;
                    setIsLoading(true);

                    wipe(uuid, confirmWipe!.uuid)
                        .catch((error) => {
                            console.error(error);
                            clearAndAddHttpError({ error, key: 'players:view' });
                            setPlayer(confirmWipe);
                        })
                        .finally(() => {
                            setIsLoading(false);
                            setConfirmWipe(undefined);
                        });
                }}
                confirm={'Confirm'}
            >
                {confirmWipe && (
                    <>
                        <div className={'z-50 left-6 top-4 absolute h-8 flex flex-row items-center'}>
                            <img src={confirmWipe.avatar} alt={confirmWipe.name} className={'w-8 h-8 rounded-md'} />
                            <span className={'ml-2 flex flex-col justify-center'}>
                                <h1 className={'text-lg'}>{confirmWipe.name}</h1>
                                <p className={'-mt-2 text-sm text-neutral-400'}>
                                    {isPlayerOnline(confirmWipe)
                                        ? 'Online'
                                        : 'Offline'}
                                </p>
                            </span>
                        </div>
                        <Banner
                            title={'Warning'}
                            className={'bg-red-600 mt-10'}
                            icon={<FontAwesomeIcon icon={faExclamationTriangle} />}
                        >
                            Wiping all player data associated with this player is permanent and cannot be undone. This
                            includes all player data, inventories, and any other information stored by the server. If
                            the player is currently online, they will be kicked from the server.
                        </Banner>
                        <p className={'mt-2'}>
                            Are you sure you want to wipe <strong>{confirmWipe.name}</strong> on this server?
                        </p>
                    </>
                )}
            </Dialog.Confirm>
            <Dialog.Confirm
                open={Boolean(confirmIpBan)}
                onClose={() => {
                    setPlayer(confirmIpBan);
                    setConfirmIpBan(undefined);
                }}
                onConfirmed={() => {
                    if (isLoading || reason.length < 3 || reason.length > 255) return;
                    setIsLoading(true);

                    banipPlayer(uuid, confirmIpBan!.uuid, reason)
                        .then(() => mutate())
                        .then(() => setReason(''))
                        .catch((error) => {
                            console.error(error);
                            clearAndAddHttpError({ error, key: 'players:view' });
                            setPlayer(confirmIpBan);
                        })
                        .finally(() => {
                            setIsLoading(false);
                            setConfirmIpBan(undefined);
                        });
                }}
                confirm={'Confirm'}
            >
                {confirmIpBan && (
                    <>
                        <div className={'z-50 left-6 top-4 absolute h-8 flex flex-row items-center'}>
                            <img src={confirmIpBan.avatar} alt={confirmIpBan.name} className={'w-8 h-8 rounded-md'} />
                            <span className={'ml-2 flex flex-col justify-center'}>
                                <h1 className={'text-lg'}>{confirmIpBan.name}</h1>
                                <p className={'-mt-2 text-sm text-neutral-400'}>
                                    {isPlayerOnline(confirmIpBan)
                                        ? 'Online'
                                        : 'Offline'}
                                </p>
                            </span>
                        </div>
                        <Banner
                            title={'Information'}
                            className={'bg-blue-600 mt-10'}
                            icon={<FontAwesomeIcon icon={faInfoCircle} />}
                        >
                            Banning a player will no longer make them able to join this server until they are unbanned.
                            This ban is permanent until you remove it.
                        </Banner>
                        <p className={'mt-2'}>
                            Are you sure you want to ban the IP of <strong>{confirmIpBan.name}</strong> from this
                            server?
                        </p>

                        <Label className={'mt-6'}>Reason</Label>
                        <Input.Text
                            placeholder={'Griefing'}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                    </>
                )}
            </Dialog.Confirm>
            <Dialog.Confirm
                open={Boolean(confirmKill)}
                onClose={() => {
                    setPlayer(confirmKill);
                    setConfirmKill(undefined);
                }}
                onConfirmed={() => {
                    if (isLoading) return;
                    setIsLoading(true);

                    kill(uuid, confirmKill!.uuid)
                        .catch((error) => {
                            console.error(error);
                            clearAndAddHttpError({ error, key: 'players:view' });
                            setPlayer(confirmKill);
                        })
                        .finally(() => {
                            setIsLoading(false);
                            setConfirmKill(undefined);
                        });
                }}
                confirm={'Confirm'}
            >
                {confirmKill && (
                    <>
                        <div className={'z-50 left-6 top-4 absolute h-8 flex flex-row items-center'}>
                            <img src={confirmKill.avatar} alt={confirmKill.name} className={'w-8 h-8 rounded-md'} />
                            <span className={'ml-2 flex flex-col justify-center'}>
                                <h1 className={'text-lg'}>{confirmKill.name}</h1>
                                <p className={'-mt-2 text-sm text-neutral-400'}>
                                    {isPlayerOnline(confirmKill)
                                        ? 'Online'
                                        : 'Offline'}
                                </p>
                            </span>
                        </div>
                        <Banner
                            title={'Warning'}
                            className={'bg-red-600 mt-10'}
                            icon={<FontAwesomeIcon icon={faExclamationTriangle} />}
                        >
                            Killing a player will make them lose all of their items and will respawn them at the spawn
                            point (or their bed if they have one). This action cannot be undone.
                        </Banner>
                        <p className={'mt-2'}>
                            Are you sure you want to kill <strong>{confirmKill.name}</strong>?
                        </p>
                    </>
                )}
            </Dialog.Confirm>

            <Dialog open={Boolean(player)} onClose={() => setPlayer(undefined)} panelClassName={'!max-w-5xl w-full'}>
                {player && (
                    <>
                        <div className={'z-50 left-6 top-4 absolute h-8 flex flex-row items-center'}>
                            <img src={player.avatar} alt={''} className={'w-8 h-8 rounded-md'} />
                            <span className={'ml-2 flex flex-col justify-center'}>
                                <span className={'flex items-center gap-2 flex-wrap'}>
                                    <h1 className={'text-lg'}>{player.name}</h1>
                                    {isPlayerOpped(player) && (
                                        <span
                                            className={
                                                'inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-500/20 text-amber-400 border border-amber-500/40'
                                            }
                                            title={'Operator'}
                                        >
                                            <FontAwesomeIcon icon={faCrown} className={'text-amber-400'} />
                                            OP
                                        </span>
                                    )}
                                </span>
                                <p
                                    className={
                                        selectedPlayerIsOnline
                                            ? '-mt-2 text-sm text-green-500 font-medium'
                                            : '-mt-2 text-sm text-neutral-400'
                                    }
                                >
                                    {selectedPlayerIsOnline
                                        ? 'Online'
                                        : 'Offline'}
                                </p>
                            </span>
                        </div>

                        <div className={'w-full pt-10 flex flex-row'}>
                            <div className={'bg-gray-700 rounded-md md:block relative hidden h-72 w-60 mr-4 mb-5'}>
                                <div
                                    className={
                                        'left-0 top-0 bottom-0 right-0 m-auto absolute flex flex-row items-center justify-center'
                                    }
                                >
                                    <Spinner size={'small'} centered />
                                </div>

                                <iframe
                                    src={`/api/client/extensions/minecraftplayermanager/servers/${uuid}/skin?uuid=${player.uuid.replace(
                                        /-/g,
                                        ''
                                    )}&height=256&width=128`}
                                    loading={'lazy'}
                                    referrerPolicy={'no-referrer'}
                                    sandbox={'allow-scripts allow-same-origin'}
                                    className={'h-64 left-0 top-0 bottom-0 right-0 m-auto w-36 absolute'}
                                />
                            </div>
                            <div className={'flex flex-col w-full'}>
                                <FlashMessageRender byKey={'players:view'} className={'mb-2'} />

                                {playerPage === 'actions' ? (
                                    <>
                                        <h2 className={'text-lg flex flex-row items-center mb-1'}>
                                            <FontAwesomeIcon icon={faTag} className={'mr-2'} />
                                            UUID
                                        </h2>
                                        <code className={'font-mono bg-neutral-700 rounded py-1 px-2 w-full block'}>
                                            {player.uuid}
                                        </code>

                                        <h2 className={'text-lg flex flex-row items-center mb-1 mt-3'}>
                                            <FontAwesomeIcon icon={faUserCog} className={'mr-2'} />
                                            Power actions
                                        </h2>
                                        <div className={'grid grid-cols-2 w-full gap-2'}>
                                            {isPlayerWhitelisted(player) ? (
                                                <Button.Danger
                                                    className={'w-full'}
                                                    disabled={isLoading}
                                                    onClick={() => {
                                                        setIsLoading(true);

                                                        removeWhitelist(uuid, player.uuid)
                                                            .then(() =>
                                                                mutate(
                                                                    {
                                                                        ...query,
                                                                        whitelist: {
                                                                            ...query.whitelist,
                                                                            list: query.whitelist.list.filter(
                                                                                (p) => p.uuid !== player.uuid
                                                                            ),
                                                                        },
                                                                    },
                                                                    false
                                                                )
                                                            )
                                                            .catch((error) => {
                                                                console.error(error);
                                                                clearAndAddHttpError({ error, key: 'players:view' });
                                                            })
                                                            .finally(() => setIsLoading(false));
                                                    }}
                                                >
                                                    Unwhitelist
                                                </Button.Danger>
                                            ) : (
                                                <Button.Text
                                                    className={'w-full'}
                                                    disabled={isLoading}
                                                    onClick={() => {
                                                        setIsLoading(true);

                                                        addWhitelist(uuid, player.name)
                                                            .then(() =>
                                                                mutate(
                                                                    {
                                                                        ...query,
                                                                        whitelist: {
                                                                            ...query.whitelist,
                                                                            list: [...query.whitelist.list, player],
                                                                        },
                                                                    },
                                                                    false
                                                                )
                                                            )
                                                            .catch((error) => {
                                                                console.error(error);
                                                                clearAndAddHttpError({ error, key: 'players:view' });
                                                            })
                                                            .finally(() => setIsLoading(false));
                                                    }}
                                                >
                                                    Whitelist
                                                </Button.Text>
                                            )}
                                            {isPlayerOpped(player) ? (
                                                <Button.Danger
                                                    className={'w-full'}
                                                    disabled={isLoading}
                                                    onClick={() => {
                                                        setIsLoading(true);

                                                        deop(uuid, player.uuid)
                                                            .then(() =>
                                                                mutate(
                                                                    {
                                                                        ...query,
                                                                        opped: query.opped.filter(
                                                                            (p) => p.uuid !== player.uuid
                                                                        ),
                                                                    },
                                                                    false
                                                                )
                                                            )
                                                            .catch((error) => {
                                                                console.error(error);
                                                                clearAndAddHttpError({ error, key: 'players:view' });
                                                            })
                                                            .finally(() => setIsLoading(false));
                                                    }}
                                                >
                                                    Revoke OP
                                                </Button.Danger>
                                            ) : (
                                                <Button.Text
                                                    className={'w-full'}
                                                    disabled={isLoading}
                                                    onClick={() => {
                                                        setConfirmOp(player);
                                                        setPlayer(undefined);
                                                    }}
                                                >
                                                    Op player
                                                </Button.Text>
                                            )}
                                            {isPlayerBanned(player) ? (
                                                <Button.Text
                                                    className={'w-full'}
                                                    disabled={isLoading}
                                                    onClick={() => {
                                                        setIsLoading(true);

                                                        unban(uuid, player.uuid)
                                                            .then(() =>
                                                                mutate(
                                                                    {
                                                                        ...query,
                                                                        banned: {
                                                                            ...query.banned,
                                                                            players: query.banned.players.filter(
                                                                                (p) => p.uuid !== player.uuid
                                                                            ),
                                                                        },
                                                                    },
                                                                    false
                                                                )
                                                            )
                                                            .catch((error) => {
                                                                console.error(error);
                                                                clearAndAddHttpError({ error, key: 'players:view' });
                                                            })
                                                            .finally(() => setIsLoading(false));
                                                    }}
                                                >
                                                    Unban player
                                                </Button.Text>
                                            ) : (
                                                <Button.Danger
                                                    className={'w-full'}
                                                    disabled={isLoading}
                                                    onClick={() => {
                                                        setConfirmBan(player);
                                                        setPlayer(undefined);
                                                    }}
                                                >
                                                    Ban player
                                                </Button.Danger>
                                            )}
                                            <Button.Danger
                                                className={'w-full'}
                                                disabled={isLoading}
                                                onClick={() => {
                                                    setConfirmWipe(player);
                                                    setPlayer(undefined);
                                                }}
                                            >
                                                Wipe player
                                            </Button.Danger>
                                            <Button.Danger
                                                className={'w-full'}
                                                disabled={
                                                    isLoading || !selectedPlayerIsOnline
                                                }
                                                onClick={() => {
                                                    setConfirmKick(player);
                                                    setPlayer(undefined);
                                                }}
                                            >
                                                Kick player
                                            </Button.Danger>
                                            <Button.Danger
                                                className={'w-full'}
                                                disabled={
                                                    isLoading || !selectedPlayerIsOnline
                                                }
                                                onClick={() => {
                                                    setConfirmClear(player);
                                                    setPlayer(undefined);
                                                }}
                                            >
                                                Clear inventory
                                            </Button.Danger>
                                            <Button.Danger
                                                className={'w-full'}
                                                disabled={
                                                    isLoading || !selectedPlayerIsOnline
                                                }
                                                onClick={() => {
                                                    setConfirmIpBan(player);
                                                    setPlayer(undefined);
                                                }}
                                            >
                                                Ban IP
                                            </Button.Danger>
                                            <Button.Danger
                                                className={'w-full'}
                                                disabled={
                                                    isLoading || !selectedPlayerIsOnline
                                                }
                                                onClick={() => {
                                                    setConfirmKill(player);
                                                    setPlayer(undefined);
                                                }}
                                            >
                                                Kill player
                                            </Button.Danger>
                                        </div>
                                    </>
                                ) : playerPage === 'inventory' ? (
                                    !inventoryItems ? (
                                        <Spinner size={'large'} centered />
                                    ) : (
                                        <PlayerInventoryGrid
                                            items={inventoryItems}
                                            isLoading={isLoading}
                                            onRemoveItem={async (item) => {
                                                if (!player || isLoading) return;

                                                const confirmed = window.confirm(
                                                    `Delete item from slot ${item.slot}? This action cannot be undone.`
                                                );
                                                if (!confirmed) return;

                                                setIsLoading(true);
                                                try {
                                                    await removeInventoryItem(uuid, player.uuid, item.slot);
                                                    await mutateGlobal(['players', 'inventory', uuid, player.uuid]);
                                                } catch (error) {
                                                    console.error(error);
                                                    clearAndAddHttpError({ error, key: 'players:view' });
                                                } finally {
                                                    setIsLoading(false);
                                                }
                                            }}
                                        />
                                    )
                                ) : !stats ? (
                                    <Spinner size={'large'} centered />
                                ) : (
                                    <>
                                        <h2 className={'text-lg flex flex-row items-center mb-1'}>
                                            <FontAwesomeIcon icon={faLocationArrow} className={'mr-2'} />
                                            Position
                                        </h2>
                                        <code className={'font-mono bg-neutral-700 rounded py-1 px-2 w-full block mb-2'}>
                                            {stats.world ?? 'unknown'} @{' '}
                                            {stats.position?.x != null ? Math.floor(stats.position.x) : '?'}{' '}
                                            {stats.position?.y != null ? Math.floor(stats.position.y) : '?'}{' '}
                                            {stats.position?.z != null ? Math.floor(stats.position.z) : '?'}
                                        </code>
                                        {selectedPlayerIsOnline && (
                                            <div className={'flex flex-nowrap items-end gap-2 mb-3'}>
                                                <Input.Text
                                                    type={'number'}
                                                    placeholder={'X'}
                                                    className={'w-14 min-w-0'}
                                                    defaultValue={stats.position?.x != null ? Math.floor(stats.position.x) : ''}
                                                    id={'stats-pos-x'}
                                                />
                                                <Input.Text
                                                    type={'number'}
                                                    placeholder={'Y'}
                                                    className={'w-14 min-w-0'}
                                                    defaultValue={stats.position?.y != null ? Math.floor(stats.position.y) : ''}
                                                    id={'stats-pos-y'}
                                                />
                                                <Input.Text
                                                    type={'number'}
                                                    placeholder={'Z'}
                                                    className={'w-14 min-w-0'}
                                                    defaultValue={stats.position?.z != null ? Math.floor(stats.position.z) : ''}
                                                    id={'stats-pos-z'}
                                                />
                                                <Button
                                                    size={Button.Sizes.Small}
                                                    disabled={isLoading}
                                                    onClick={async () => {
                                                        const x = Number((document.getElementById('stats-pos-x') as HTMLInputElement)?.value);
                                                        const y = Number((document.getElementById('stats-pos-y') as HTMLInputElement)?.value);
                                                        const z = Number((document.getElementById('stats-pos-z') as HTMLInputElement)?.value);
                                                        if (Number.isNaN(x) || Number.isNaN(y) || Number.isNaN(z)) return;
                                                        setIsLoading(true);
                                                        try {
                                                            await setPosition(uuid, player.uuid, { x, y, z });
                                                            await mutateGlobal(['players', 'stats', uuid, player.uuid]);
                                                        } catch (e) {
                                                            clearAndAddHttpError({ error: e as Error, key: 'players:view' });
                                                        } finally {
                                                            setIsLoading(false);
                                                        }
                                                    }}
                                                >
                                                    Apply
                                                </Button>
                                            </div>
                                        )}

                                        <div className={'flex flex-row items-center mt-3'}>
                                            <div className={'flex flex-col w-1/2 pr-1'}>
                                                <h2 className={'text-lg flex flex-row items-center mb-1'}>
                                                    <FontAwesomeIcon icon={faCog} className={'mr-2'} />
                                                    Gamemode
                                                </h2>
                                                <code
                                                    className={
                                                        'font-mono bg-neutral-700 rounded py-1 px-2 w-full block'
                                                    }
                                                >
                                                    {stats.gamemode ?? 'unknown'}
                                                </code>
                                                {selectedPlayerIsOnline && (
                                                    <div className={'flex items-center gap-2 mt-1'}>
                                                        <Select
                                                            id={'stats-gamemode'}
                                                            className={'flex-1'}
                                                            defaultValue={stats.gamemode ?? 'survival'}
                                                        >
                                                            <option value={'survival'}>Survival</option>
                                                            <option value={'creative'}>Creative</option>
                                                            <option value={'adventure'}>Adventure</option>
                                                            <option value={'spectator'}>Spectator</option>
                                                        </Select>
                                                        <Button
                                                            size={Button.Sizes.Small}
                                                            disabled={isLoading}
                                                            onClick={async () => {
                                                                const mode = (document.getElementById('stats-gamemode') as HTMLSelectElement)?.value as Gamemode;
                                                                if (!mode) return;
                                                                setIsLoading(true);
                                                                try {
                                                                    await setGamemode(uuid, player.uuid, mode);
                                                                    await mutateGlobal(['players', 'stats', uuid, player.uuid]);
                                                                } catch (e) {
                                                                    clearAndAddHttpError({ error: e as Error, key: 'players:view' });
                                                                } finally {
                                                                    setIsLoading(false);
                                                                }
                                                            }}
                                                        >
                                                            Apply
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>

                                            <div className={'flex flex-col w-1/2 pl-1'}>
                                                <h2 className={'text-lg flex flex-row items-center mb-1'}>
                                                    <FontAwesomeIcon icon={faUserClock} className={'mr-2'} />
                                                    Playtime
                                                </h2>
                                                <code
                                                    className={
                                                        'font-mono bg-neutral-700 rounded py-1 px-2 w-full block'
                                                    }
                                                >
                                                    {stats.playtime ? (
                                                        <UptimeDuration uptime={stats.playtime} />
                                                    ) : (
                                                        'unknown'
                                                    )}
                                                </code>
                                            </div>
                                        </div>

                                        <div className={'flex flex-row items-center mt-3'}>
                                            <div className={'flex flex-col w-1/2 pr-1'}>
                                                <h2 className={'text-lg flex flex-row items-center mb-1'}>
                                                    <FontAwesomeIcon icon={faSkull} className={'mr-2'} />
                                                    Kills
                                                </h2>
                                                <code
                                                    className={
                                                        'font-mono bg-neutral-700 rounded py-1 px-2 w-full block'
                                                    }
                                                >
                                                    {stats.kills ?? 0}
                                                </code>
                                            </div>

                                            <div className={'flex flex-col w-1/2 pl-1'}>
                                                <h2 className={'text-lg flex flex-row items-center mb-1'}>
                                                    <FontAwesomeIcon icon={faSkullCrossbones} className={'mr-2'} />
                                                    Deaths
                                                </h2>
                                                <code
                                                    className={
                                                        'font-mono bg-neutral-700 rounded py-1 px-2 w-full block'
                                                    }
                                                >
                                                    {stats.deaths ?? 0}
                                                </code>
                                            </div>
                                        </div>

                                        <div className={'flex flex-row items-center mt-3'}>
                                            <div className={'flex flex-col w-1/2 pr-1'}>
                                                <h2 className={'text-lg flex flex-row items-center mb-1'}>
                                                    <FontAwesomeIcon icon={faWineBottle} className={'mr-2'} />
                                                    Level
                                                </h2>
                                                <code
                                                    className={
                                                        'font-mono bg-neutral-700 rounded py-1 px-2 w-full block'
                                                    }
                                                >
                                                    {stats.xp_level ?? 0} ({stats.xp_total ?? 0} XP)
                                                </code>
                                                {selectedPlayerIsOnline && (
                                                    <div className={'flex items-center gap-2 mt-1'}>
                                                        <Input.Text
                                                            type={'number'}
                                                            min={0}
                                                            max={24791}
                                                            placeholder={'Level'}
                                                            className={'w-24'}
                                                            id={'stats-level'}
                                                            defaultValue={stats.xp_level ?? 0}
                                                        />
                                                        <Button
                                                            size={Button.Sizes.Small}
                                                            disabled={isLoading}
                                                            onClick={async () => {
                                                                const level = Number((document.getElementById('stats-level') as HTMLInputElement)?.value);
                                                                if (Number.isNaN(level) || level < 0) return;
                                                                setIsLoading(true);
                                                                try {
                                                                    await setLevel(uuid, player.uuid, level);
                                                                    await mutateGlobal(['players', 'stats', uuid, player.uuid]);
                                                                } catch (e) {
                                                                    clearAndAddHttpError({ error: e as Error, key: 'players:view' });
                                                                } finally {
                                                                    setIsLoading(false);
                                                                }
                                                            }}
                                                        >
                                                            Apply
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>

                                            <div className={'flex flex-col w-1/2 pl-1'}>
                                                <h2 className={'text-lg flex flex-row items-center mb-1'}>
                                                    <FontAwesomeIcon icon={faCross} className={'mr-2'} />
                                                    Last Death
                                                </h2>
                                                <code
                                                    className={
                                                        'font-mono bg-neutral-700 rounded py-1 px-2 w-full block'
                                                    }
                                                >
                                                    {stats.last_death && stats.last_death !== stats.playtime ? (
                                                        <UptimeDuration uptime={stats.last_death} />
                                                    ) : (
                                                        'unknown'
                                                    )}
                                                </code>
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>

                        <Dialog.Footer>
                            <Button.Text
                                variant={Button.Variants.Secondary}
                                onClick={() => setPlayerPage('actions')}
                                disabled={isLoading || playerPage === 'actions'}
                            >
                                Actions
                            </Button.Text>
                            <Button.Text
                                variant={Button.Variants.Secondary}
                                onClick={() => setPlayerPage('stats')}
                                disabled={isLoading || playerPage === 'stats'}
                            >
                                Stats
                            </Button.Text>
                            <Button.Text
                                variant={Button.Variants.Secondary}
                                onClick={() => setPlayerPage('inventory')}
                                disabled={isLoading || playerPage === 'inventory'}
                            >
                                <FontAwesomeIcon icon={faBox} className={'mr-2'} />
                                Inventory
                            </Button.Text>
                            <Button.Text className={'h-full'} onClick={() => setPlayer(undefined)}>
                                Close
                            </Button.Text>
                        </Dialog.Footer>
                    </>
                )}
            </Dialog>

            <Dialog open={bannedIpsModalVisible} onClose={() => setBannedIpsModalVisible(false)} title={'Banned IPs'}>
                <Banner title={'Information'} className={'bg-blue-600'} icon={<FontAwesomeIcon icon={faInfoCircle} />}>
                    IP addresses listed here are banned from connecting to this server. Removing IP addresses from this
                    list will restore their access.
                </Banner>

                <div className={'flex flex-col w-full mt-5'}>
                    <div className={'flex flex-row items-center'}>
                        <Input.Text
                            className={'mr-14'}
                            placeholder={'127.0.0.1'}
                            value={address}
                            onChange={(e) => setAddress(e.target.value)}
                            disabled={isLoading}
                        />
                        <Button.Danger
                            className={'ml-4 max-h-[44px] max-w-[44px] absolute right-6'}
                            shape={Button.Shapes.IconSquare}
                            onClick={() => {
                                setIsLoading(true);

                                banip(uuid, address, 'Banned IP')
                                    .then(() =>
                                        mutate(
                                            {
                                                ...query,
                                                banned: {
                                                    ...query.banned,
                                                    ips: [...query.banned.ips, { ip: address, reason: 'Banned IP' }],
                                                },
                                            },
                                            false
                                        )
                                    )
                                    .then(() => setAddress(''))
                                    .catch((error) => {
                                        console.error(error);
                                        clearAndAddHttpError({ error, key: 'players:view' });
                                    })
                                    .finally(() => {
                                        setIsLoading(false);
                                        setAddress('');
                                    });
                            }}
                            disabled={
                                isLoading || address.length < 7 || query.banned.ips.some((ip) => ip.ip === address)
                            }
                        >
                            <FontAwesomeIcon icon={faBan} />
                        </Button.Danger>
                    </div>
                    {query.banned.ips.map((ip) => (
                        <div key={ip.ip} className={'flex flex-row items-center mt-3'}>
                            <Input.Text className={'mr-14'} value={ip.ip} disabled />
                            <Button.Text
                                className={'ml-4 max-h-[44px] max-w-[44px] absolute right-6'}
                                shape={Button.Shapes.IconSquare}
                                onClick={() => {
                                    setIsLoading(true);

                                    unbanip(uuid, ip.ip)
                                        .then(() =>
                                            mutate(
                                                {
                                                    ...query,
                                                    banned: {
                                                        ...query.banned,
                                                        ips: query.banned.ips.filter((i) => i.ip !== ip.ip),
                                                    },
                                                },
                                                false
                                            )
                                        )
                                        .catch((error) => {
                                            console.error(error);
                                            clearAndAddHttpError({ error, key: 'players:view' });
                                        })
                                        .finally(() => setIsLoading(false));
                                }}
                                disabled={isLoading}
                            >
                                <FontAwesomeIcon icon={faTrash} />
                            </Button.Text>
                        </div>
                    ))}
                </div>

                <Dialog.Footer>
                    <Button.Text onClick={() => setBannedIpsModalVisible(false)}>Back</Button.Text>
                </Dialog.Footer>
            </Dialog>

            <Dialog open={newOpModalVisible} onClose={() => setNewOpModalVisible(false)} title={'Op Player'}>
                <form
                    id={'op-player-form'}
                    onSubmit={(e) => {
                        e.preventDefault();
                        e.stopPropagation();

                        if (isLoading || playerInput.length < 3) return;
                        setIsLoading(true);

                        clearFlashes();

                        op(uuid, playerInput)
                            .then(() => mutate())
                            .then(() => setPlayerInput(''))
                            .catch((error) => {
                                console.error(error);
                                clearAndAddHttpError({ error, key: 'players:view' });
                            })
                            .finally(() => {
                                setIsLoading(false);
                                setPlayer(undefined);
                                setNewOpModalVisible(false);
                            });
                    }}
                >
                    <Label>Player Name</Label>
                    <Input.Text
                        placeholder={'Notch'}
                        value={playerInput}
                        onChange={(e) => setPlayerInput(e.target.value)}
                    />

                    <Dialog.Footer>
                        <Button.Text onClick={() => setNewOpModalVisible(false)}>Cancel</Button.Text>
                        <Button disabled={isLoading || playerInput.length < 3} type={'submit'} form={'op-player-form'}>
                            Op Player
                        </Button>
                    </Dialog.Footer>
                </form>
            </Dialog>

            <Dialog
                open={newWhitelistModalVisible}
                onClose={() => setNewWhitelistModalVisible(false)}
                title={'Add to Whitelist'}
            >
                <form
                    id={'whitelist-player-form'}
                    onSubmit={(e) => {
                        e.preventDefault();
                        e.stopPropagation();

                        if (isLoading || playerInput.length < 3) return;
                        setIsLoading(true);

                        clearFlashes();

                        addWhitelist(uuid, playerInput)
                            .then(() => mutate())
                            .then(() => setPlayerInput(''))
                            .catch((error) => {
                                console.error(error);
                                clearAndAddHttpError({ error, key: 'players:view' });
                            })
                            .finally(() => {
                                setIsLoading(false);
                                setPlayer(undefined);
                                setNewWhitelistModalVisible(false);
                            });
                    }}
                >
                    <Label>Player Name</Label>
                    <Input.Text
                        placeholder={'Notch'}
                        value={playerInput}
                        onChange={(e) => setPlayerInput(e.target.value)}
                    />

                    <Dialog.Footer>
                        <Button.Text onClick={() => setNewWhitelistModalVisible(false)}>Cancel</Button.Text>
                        <Button
                            disabled={isLoading || playerInput.length < 3}
                            type={'submit'}
                            form={'whitelist-player-form'}
                        >
                            Add to Whitelist
                        </Button>
                    </Dialog.Footer>
                </form>
            </Dialog>

            <Dialog open={newBanModalVisible} onClose={() => setNewBanModalVisible(false)} title={'Ban Player'}>
                <form
                    id={'ban-player-form'}
                    onSubmit={(e) => {
                        e.preventDefault();
                        e.stopPropagation();

                        if (isLoading || playerInput.length < 3 || reason.length < 3) return;
                        setIsLoading(true);

                        clearFlashes();

                        ban(uuid, playerInput, reason)
                            .then(() => mutate())
                            .then(() => setPlayerInput(''))
                            .then(() => setReason(''))
                            .catch((error) => {
                                console.error(error);
                                clearAndAddHttpError({ error, key: 'players:view' });
                            })
                            .finally(() => {
                                setIsLoading(false);
                                setPlayer(undefined);
                                setNewBanModalVisible(false);
                            });
                    }}
                >
                    <Label>Player Name</Label>
                    <Input.Text
                        placeholder={'Notch'}
                        value={playerInput}
                        onChange={(e) => setPlayerInput(e.target.value)}
                    />

                    <Label className={'mt-6'}>Reason</Label>
                    <Input.Text placeholder={'Griefing'} value={reason} onChange={(e) => setReason(e.target.value)} />

                    <Dialog.Footer>
                        <Button.Text onClick={() => setNewBanModalVisible(false)}>Cancel</Button.Text>
                        <Button
                            disabled={isLoading || playerInput.length < 3 || reason.length < 3}
                            type={'submit'}
                            form={'ban-player-form'}
                        >
                            Ban Player
                        </Button>
                    </Dialog.Footer>
                </form>
            </Dialog>

            <FlashMessageRender byKey={'players:view'} className={'mb-4'} />

            <div className={'flex flex-col w-full'}>
                {!query.is_proxy ? (
                    <>
                        <div className={'mb-4 flex flex-col md:flex-row md:justify-between md:items-center gap-3 w-full'}>
                            <h1 className={'text-2xl'}>Player management</h1>
                            <div className={'flex flex-wrap gap-2'}>
                                <Button.Text onClick={() => setBannedIpsModalVisible(true)}>View Banned IPs</Button.Text>
                                <Button.Text
                                    disabled={isLoading}
                                    onClick={() => {
                                        const nextEnabled = !query.whitelist.enabled;
                                        if (nextEnabled) {
                                            setConfirmWhitelistEnabled(true);
                                            return;
                                        }

                                        applyWhitelistEnabled(false);
                                    }}
                                >
                                    {query.whitelist.enabled ? 'Disable whitelist' : 'Enable whitelist'}
                                </Button.Text>
                            </div>
                        </div>

                        <p className={'text-sm text-neutral-400 mb-2'}>
                            Online players: {query.online ? query.players.online : 0}
                        </p>

                        <div className={'mt-2 w-full flex flex-col gap-3'}>
                            {offlineWarnings.length > 0 && (
                                <Banner
                                    title={'Warning'}
                                    className={'bg-amber-600'}
                                    icon={<FontAwesomeIcon icon={faExclamationTriangle} />}
                                >
                                    {offlineWarnings.join(' ')}
                                </Banner>
                            )}

                            {!!allPlayers.length && (
                                <div className={'grid grid-cols-7 gap-2 items-center justify-between'}>
                                    <OldInput
                                        placeholder={'Search players...'}
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className={'w-full col-span-3'}
                                    />

                                    <Select
                                        value={tableFilter}
                                        onChange={(e) => setTableFilter(e.target.value as 'all' | 'opped' | 'whitelisted' | 'banned')}
                                        className={'col-span-1'}
                                    >
                                        <option value={'all'}>All</option>
                                        <option value={'opped'}>Only OP</option>
                                        <option value={'whitelisted'}>Only Whitelisted</option>
                                        <option value={'banned'}>Only Banned</option>
                                    </Select>

                                    <Select
                                        value={limit}
                                        onChange={(e) => setLimit(Number(e.target.value))}
                                        className={'col-span-1'}
                                    >
                                        <option value={10}>10 players</option>
                                        <option value={25}>25 players</option>
                                        <option value={50}>50 players</option>
                                        <option value={100}>100 players</option>
                                        <option value={250}>250 players</option>
                                    </Select>
                                    <label
                                        className={
                                            'col-span-2 flex items-center justify-end gap-2 text-xs text-neutral-300 whitespace-nowrap'
                                        }
                                    >
                                        <input
                                            type={'checkbox'}
                                            checked={successAuthOnly}
                                            onChange={(e) => setSuccessAuthOnly(e.currentTarget.checked)}
                                        />
                                        Success auth
                                    </label>
                                </div>
                            )}
                            <PlayerTable
                                players={filteredAllPlayers}
                                emptyMessage={'No players are currently available.'}
                                onOpen={(nextPlayer) => setPlayer(nextPlayer)}
                                isOnline={(nextPlayer) => onlinePlayerUuids.has(normalizeUuid(nextPlayer.uuid))}
                                isOp={isPlayerOpped}
                                isWhitelisted={isPlayerWhitelisted}
                                isBanned={isPlayerBanned}
                                showReason
                                defaultSortKey={'status'}
                                defaultSortDirection={'desc'}
                            />
                        </div>
                    </>
                ) : (
                    <p className={'text-neutral-400 text-sm'}>This server is a proxy. Player management is disabled.</p>
                )}
            </div>
        </ServerContentBlock>
    );
}
