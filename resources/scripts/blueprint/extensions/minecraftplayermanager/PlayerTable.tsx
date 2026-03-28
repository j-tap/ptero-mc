import React, { useMemo, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCrown, faSort, faSortDown, faSortUp } from '@fortawesome/free-solid-svg-icons';
import { Player } from './api/getStatus';
import UptimeDuration from '@/components/server/UptimeDuration';

type PlayerTableEntry = Player & {
    reason?: string;
    playtime?: number | null;
    first_seen_at?: string | null;
    last_logout_at?: string | null;
};

type SortKey = 'name' | 'status' | 'playtime' | 'first_seen_at' | 'last_logout_at' | 'reason';
type SortDirection = 'asc' | 'desc';

type Props = {
    players: PlayerTableEntry[];
    emptyMessage: string;
    onOpen: (player: PlayerTableEntry) => void;
    isOp?: (player: PlayerTableEntry) => boolean;
    isOnline?: (player: PlayerTableEntry) => boolean;
    showReason?: boolean;
    defaultSortKey?: SortKey;
    defaultSortDirection?: SortDirection;
};

const dateFormatter = new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
});

function toTimestamp(value?: string | null): number {
    if (!value) {
        return -1;
    }

    const timestamp = Date.parse(value);
    return Number.isNaN(timestamp) ? -1 : timestamp;
}

function renderDate(value?: string | null): string {
    const timestamp = toTimestamp(value);
    if (timestamp < 0) {
        return '-';
    }

    return dateFormatter.format(new Date(timestamp));
}

export default function PlayerTable({
    players,
    emptyMessage,
    onOpen,
    isOp = () => false,
    isOnline = () => false,
    showReason = false,
    defaultSortKey = 'name',
    defaultSortDirection = 'asc',
}: Props) {
    const [sortKey, setSortKey] = useState<SortKey>(defaultSortKey);
    const [sortDirection, setSortDirection] = useState<SortDirection>(defaultSortDirection);

    const sortedPlayers = useMemo(() => {
        const copy = [...players];
        copy.sort((left, right) => {
            const leftStatus = isOnline(left);
            const rightStatus = isOnline(right);

            const compareByKey: Record<SortKey, number> = {
                name: left.name.localeCompare(right.name, undefined, { sensitivity: 'base' }),
                status: Number(leftStatus) - Number(rightStatus),
                playtime: (left.playtime ?? -1) - (right.playtime ?? -1),
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
    }, [isOnline, players, sortDirection, sortKey]);

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
            <table className={'w-full min-w-[52rem] bg-gray-800'}>
                <thead className={'bg-gray-700/70 text-left'}>
                    <tr>
                        <th className={'px-3 py-2'}>
                            <button type={'button'} className={'flex items-center gap-2'} onClick={() => toggleSort('name')}>
                                Player
                                <FontAwesomeIcon icon={sortIcon('name')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={'px-3 py-2'}>
                            <button type={'button'} className={'flex items-center gap-2'} onClick={() => toggleSort('status')}>
                                Status
                                <FontAwesomeIcon icon={sortIcon('status')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={'px-3 py-2'}>
                            <button
                                type={'button'}
                                className={'flex items-center gap-2'}
                                onClick={() => toggleSort('playtime')}
                            >
                                Playtime
                                <FontAwesomeIcon icon={sortIcon('playtime')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={'px-3 py-2'}>
                            <button
                                type={'button'}
                                className={'flex items-center gap-2'}
                                onClick={() => toggleSort('first_seen_at')}
                            >
                                First Login
                                <FontAwesomeIcon icon={sortIcon('first_seen_at')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        <th className={'px-3 py-2'}>
                            <button
                                type={'button'}
                                className={'flex items-center gap-2'}
                                onClick={() => toggleSort('last_logout_at')}
                            >
                                Last Logout
                                <FontAwesomeIcon icon={sortIcon('last_logout_at')} className={'text-xs text-neutral-400'} />
                            </button>
                        </th>
                        {showReason && (
                            <th className={'px-3 py-2'}>
                                <button
                                    type={'button'}
                                    className={'flex items-center gap-2'}
                                    onClick={() => toggleSort('reason')}
                                >
                                    Reason
                                    <FontAwesomeIcon icon={sortIcon('reason')} className={'text-xs text-neutral-400'} />
                                </button>
                            </th>
                        )}
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
                                    <img src={player.avatar} alt={player.name} className={'w-8 h-8 rounded'} />
                                    <span className={'inline-flex items-center gap-2'}>
                                        {player.name}
                                        {isOp(player) && (
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
                                </div>
                            </td>
                            <td className={'px-3 py-2'}>
                                {isOnline(player) ? (
                                    <span className={'text-green-400 font-medium'}>Online</span>
                                ) : (
                                    <span className={'text-neutral-400'}>Offline</span>
                                )}
                            </td>
                            <td className={'px-3 py-2'}>
                                {player.playtime && player.playtime > 0 ? <UptimeDuration uptime={player.playtime} /> : '-'}
                            </td>
                            <td className={'px-3 py-2 text-sm text-neutral-300'}>{renderDate(player.first_seen_at)}</td>
                            <td className={'px-3 py-2 text-sm text-neutral-300'}>{renderDate(player.last_logout_at)}</td>
                            {showReason && (
                                <td className={'px-3 py-2 text-sm text-neutral-300 max-w-[18rem] truncate'} title={player.reason}>
                                    {player.reason ?? '-'}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
