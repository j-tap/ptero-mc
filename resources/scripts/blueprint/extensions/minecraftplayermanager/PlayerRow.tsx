import React from "react"
import { Player } from "./api/getStatus"
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome"
import { faEllipsisV } from "@fortawesome/free-solid-svg-icons"

type Props = {
    player: Player
    extra?: string
    onOpen: () => void
}

export default function PlayerRow({ player, onOpen, extra }: Props) {
    return (
        <div className={'nebula-animation minecraftplayermanager-player-row bg-gray-700 cursor-pointer hover:bg-gray-600 transition-all p-3 rounded-md w-full min-w-[20rem] flex flex-row justify-between items-center'} onClick={onOpen}>
            <div className={'flex flex-row items-center'}>
                <img src={player.avatar} alt={player.name} className={'w-12 h-12 rounded-md'} />
                <span className={'ml-4 text-lg flex flex-col justify-center'}>
                    <h1 className={'text-lg'}>{player.name}</h1>
                    {extra && <p className={'-mt-2 text-gray-400 w-60 text-ellipsis overflow-hidden'}>{extra}</p>}
                </span>
            </div>
            <FontAwesomeIcon icon={faEllipsisV} className={'mr-5 h-12'} />
        </div>
    )
}