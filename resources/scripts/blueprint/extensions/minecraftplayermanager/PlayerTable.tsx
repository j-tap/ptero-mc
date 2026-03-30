import React, { useMemo, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSort, faSortDown, faSortUp } from '@fortawesome/free-solid-svg-icons';
import { Player } from './api/getStatus';
import UptimeDuration from '@/components/server/UptimeDuration';

type PlayerTableEntry = Player & {
    reason?: string;
    playtime?: number | null;
    session_count?: number | null;
    has_session?: boolean | null;
    reg_ip?: string | null;
    ip?: string | null;
    world?: string | null;
    first_seen_at?: string | null;
    last_logout_at?: string | null;
    licensed?: boolean | null;
};

type SortKey =
    | 'name'
    | 'status'
    | 'licensed'
    | 'opped'
    | 'whitelisted'
    | 'banned'
    | 'playtime'
    | 'session_count'
    | 'has_session'
    | 'world'
    | 'first_seen_at'
    | 'last_logout_at'
    | 'reason';
type SortDirection = 'asc' | 'desc';

type Props = {
    players: PlayerTableEntry[];
    emptyMessage: string;
    onOpen: (player: PlayerTableEntry) => void;
    isOp?: (player: PlayerTableEntry) => boolean;
    isOnline?: (player: PlayerTableEntry) => boolean;
    isWhitelisted?: (player: PlayerTableEntry) => boolean;
    isBanned?: (player: PlayerTableEntry) => boolean;
    showReason?: boolean;
    defaultSortKey?: SortKey;
    defaultSortDirection?: SortDirection;
};

const dateFormatter = new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
});

const timeFormatter = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
});

const timeFormatterWithSeconds = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
});

function toTimestamp(value?: string | null): number {
    if (!value) {
        return -1;
    }

    const timestamp = Date.parse(value);
    return Number.isNaN(timestamp) ? -1 : timestamp;
}

function renderYesDash(flag: boolean): React.ReactNode {
    return flag ? <span className={'text-green-400'}>Yes</span> : '-';
}

function renderYesNo(value: boolean | null): React.ReactNode {
    if (value === null) {
        return '-';
    }

    return value ? <span className={'text-green-400'}>Yes</span> : <span className={'text-neutral-300'}>No</span>;
}

function renderDate(value?: string | null): React.ReactNode {
    const timestamp = toTimestamp(value);
    if (timestamp < 0) {
        return '-';
    }

    const date = new Date(timestamp);
    const hasSeconds = Boolean(value && /[T\s]\d{2}:\d{2}:\d{2}/.test(value));

    return (
        <span className={'inline-flex flex-col leading-tight'}>
            <span className={'whitespace-nowrap'}>{dateFormatter.format(date)}</span>
            <span className={'whitespace-nowrap text-neutral-400'}>
                {hasSeconds ? timeFormatterWithSeconds.format(date) : timeFormatter.format(date)}
            </span>
        </span>
    );
}

export default function PlayerTable({
    players,
    emptyMessage,
    onOpen,
    isOp = () => false,
    isOnline = () => false,
    isWhitelisted = () => false,
    isBanned = () => false,
    showReason = false,
    defaultSortKey = 'name',
    defaultSortDirection = 'asc',
}: Props) {
    const [sortKey, setSortKey] = useState<SortKey>(defaultSortKey);
    const [sortDirection, setSortDirection] = useState<SortDirection>(defaultSortDirection);
    const headerCellClass = 'px-3 py-2 text-xs text-neutral-400';
    const headerButtonClass = 'flex items-center gap-1 text-xs text-neutral-400 hover:text-neutral-200 transition-colors';

    const sortedPlayers = useMemo(() => {
        const copy = [...players];
        copy.sort((left, right) => {
            const leftStatus = isOnline(left);
            const rightStatus = isOnline(right);
            const leftOpped = isOp(left);
            const rightOpped = isOp(right);
            const leftWhitelisted = isWhitelisted(left);
            const rightWhitelisted = isWhitelisted(right);
            const leftBanned = isBanned(left);
            const rightBanned = isBanned(right);

            const compareByKey: Record<SortKey, number> = {
                name: left.name.localeCompare(right.name, undefined, { sensitivity: 'base' }),
                status: Number(leftStatus) - Number(rightStatus),
                licensed: Number(left.licensed === true) - Number(right.licensed === true),
                opped: Number(leftOpped) - Number(rightOpped),
                whitelisted: Number(leftWhitelisted) - Number(rightWhitelisted),
                banned: Number(leftBanned) - Number(rightBanned),
                playtime: (left.playtime ?? -1) - (right.playtime ?? -1),
                session_count: (left.session_count ?? -1) - (right.session_count ?? -1),
                has_session: Number(Boolean(left.has_session)) - Number(Boolean(right.has_session)),
                world: (left.world ?? '').localeCompare(right.world ?? '', undefined, { sensitivity: 'base' }),
                first_seen_at: toTimestamp(left.first_seen_at) - toTimestamp(right.first_seen_at),
                last_logout_at: toTimestamp(left.last_logout_at) - toTimestamp(right.last_logout_at),
                reason: (left.reason ?? '').localeCompare(right.reason ?? '', undefined, { sensitivity: 'base' }),
            };

            const value = compareByKey[sortKey];
            if (value === 0) {
                return left.name.localeCompare(right.name, undefined, { sensitivity: 'base' });
            }

            return sortDirection === 'asc' ? value : -value;
        });

        return copy;
    }, [isBanned, isOnline, isOp, isWhitelisted, players, sortDirection, sortKey]);

    const toggleSort = (key: SortKey) => {
        if (sortKey === key) {
            setSortDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
            return;
        }

        setSortKey(key);
        setSortDirection('asc');
    };

    const sortIcon = (key: SortKey) => {
        if (sortKey !== key) {
            return faSort;
        }

        return sortDirection === 'asc' ? faSortUp : faSortDown;
    };

    if (!sortedPlayers.length) {
        return <p className={'text-sm text-neutral-400'}>{emptyMessage}</p>;
    }

    return (
        <div className={'w-full overflow-x-auto rounded-md border border-gray-700'}>
            <table className={'w-full min-w-[80rem] bg-gray-800'}>
                <thead className={'bg-gray-700/70 text-left'}>
                    <tr>
                        <th className={headerCellClass}>
                            <button type={'button'} className={headerButtonClass} onClick={() => toggleSort('name')}>
                                Player
                                <FontAwesomeIcon icon={sortIcon('name')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>
                            <button type={'button'} className={headerButtonClass} onClick={() => toggleSort('status')}>
                                Status
                                <FontAwesomeIcon icon={sortIcon('status')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>
                            <button type={'button'} className={headerButtonClass} onClick={() => toggleSort('licensed')}>
                                Licensed
                                <FontAwesomeIcon icon={sortIcon('licensed')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>
                            <button type={'button'} className={headerButtonClass} onClick={() => toggleSort('opped')}>
                                OP
                                <FontAwesomeIcon icon={sortIcon('opped')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>
                            <button type={'button'} className={headerButtonClass} onClick={() => toggleSort('whitelisted')}>
                                WL
                                <FontAwesomeIcon icon={sortIcon('whitelisted')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>
                            <button
                                type={'button'}
                                className={headerButtonClass}
                                onClick={() => toggleSort('playtime')}
                            >
                                Playtime
                                <FontAwesomeIcon icon={sortIcon('playtime')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>
                            <button
                                type={'button'}
                                className={headerButtonClass}
                                onClick={() => toggleSort('session_count')}
                            >
                                Sessions
                                <FontAwesomeIcon icon={sortIcon('session_count')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>
                            <button
                                type={'button'}
                                className={headerButtonClass}
                                onClick={() => toggleSort('has_session')}
                            >
                                hasSession
                                <FontAwesomeIcon icon={sortIcon('has_session')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>Reg IP / IP</th>
                        <th className={headerCellClass}>
                            <button
                                type={'button'}
                                className={headerButtonClass}
                                onClick={() => toggleSort('world')}
                            >
                                World
                                <FontAwesomeIcon icon={sortIcon('world')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>
                            <button type={'button'} className={headerButtonClass} onClick={() => toggleSort('banned')}>
                                Ban
                                <FontAwesomeIcon icon={sortIcon('banned')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        {showReason && (
                            <th className={headerCellClass}>
                                <button
                                    type={'button'}
                                    className={headerButtonClass}
                                    onClick={() => toggleSort('reason')}
                                >
                                    Reason
                                    <FontAwesomeIcon icon={sortIcon('reason')} className={'text-xs text-neutral-400'} />
                                </button>
                            </th>
                        )}
                        <th className={headerCellClass}>
                            <button
                                type={'button'}
                                className={headerButtonClass}
                                onClick={() => toggleSort('first_seen_at')}
                            >
                                First Login
                                <FontAwesomeIcon icon={sortIcon('first_seen_at')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={headerCellClass}>
                            <button
                                type={'button'}
                                className={headerButtonClass}
                                onClick={() => toggleSort('last_logout_at')}
                            >
                                Last Logout
                                <FontAwesomeIcon icon={sortIcon('last_logout_at')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {sortedPlayers.map((player) => (
                        <tr
                            key={player.uuid}
                            className={'border-t border-gray-700 cursor-pointer hover:bg-gray-700/70 transition-colors'}
                            onClick={() => onOpen(player)}
                        >
                            <td className={'px-3 py-2'}>
                                <div className={'flex items-center gap-3'}>
                                    <img
                                        src={player.avatar}
                                        alt={player.name}
                                        className={'mc-avatar-pixelated w-8 h-8 rounded'}
                                    />
                                    <span className={'text-sm text-neutral-200'}>{player.name}</span>
                                </div>
                            </td>
                            <td className={'px-3 py-2 text-xs'}>
                                {isOnline(player) ? (
                                    <span className={'text-green-400'}>Online</span>
                                ) : (
                                    <span className={'text-neutral-300'}>Offline</span>
                                )}
                            </td>
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>{renderYesNo(player.licensed ?? null)}</td>
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>{renderYesDash(isOp(player))}</td>
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>{renderYesDash(isWhitelisted(player))}</td>
                            <td className={'px-3 py-2 text-xs text-neutral-300 whitespace-nowrap'}>
                                {player.playtime && player.playtime > 0 ? <UptimeDuration uptime={player.playtime} /> : '-'}
                            </td>
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>{player.session_count ?? '-'}</td>
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>
                                {renderYesNo(player.has_session ?? null)}
                            </td>
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>
                                <span className={'inline-flex flex-col leading-tight'}>
                                    <span className={'whitespace-nowrap'}>{player.reg_ip ?? '-'}</span>
                                    <span className={'whitespace-nowrap text-neutral-400'}>{player.ip ?? '-'}</span>
                                </span>
                            </td>
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>{player.world ?? '-'}</td>
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>{renderYesDash(isBanned(player))}</td>
                            {showReason && (
                                <td className={'px-3 py-2 text-sm text-neutral-300 max-w-[18rem] truncate'} title={player.reason}>
                                    {player.reason ?? '-'}
                                </td>
                            )}
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>{renderDate(player.first_seen_at)}</td>
                            <td className={'px-3 py-2 text-xs text-neutral-300'}>{renderDate(player.last_logout_at)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
