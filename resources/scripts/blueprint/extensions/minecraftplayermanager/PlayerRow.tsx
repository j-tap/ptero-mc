import React from 'react';
import { Player } from './api/getStatus';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faEllipsisV, faCrown } from '@fortawesome/free-solid-svg-icons';

type Props = {
    player: Player;
    extra?: string;
    onOpen: () => void;
    isOp?: boolean;
};

export default function PlayerRow({ player, onOpen, extra, isOp }: Props) {
    return (
        <div
            className={
                'nebula-animation minecraftplayermanager-player-row bg-gray-700 cursor-pointer hover:bg-gray-600 transition-all p-3 rounded-md w-full min-w-[20rem] flex flex-row justify-between items-center'
            }
            onClick={onOpen}
        >
            <div className={'flex flex-row items-center'}>
                <img src={player.avatar} alt={player.name} className={'mc-avatar-pixelated w-12 h-12 rounded-md'} />
                <span className={'ml-4 text-lg flex flex-col justify-center'}>
                    <span className={'flex items-center gap-2 flex-wrap'}>
                        <h1 className={'text-lg'}>{player.name}</h1>
                        {isOp && (
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
                    {extra && (
                        <p className={'-mt-2 text-gray-400 w-60 text-ellipsis overflow-hidden'}>{extra}</p>
                    )}
                </span>
            </div>
            <FontAwesomeIcon icon={faEllipsisV} className={'mr-5 h-12'} />
        </div>
    );
}