import classNames from "classnames"
import React from "react"

type Props = {
    className: string
    icon: React.ReactElement
    children: string
    title: string
    extra?: React.ReactElement
}

export default function Banner({ className, icon, title, extra, children }: Props) {
    return (
        <div className={classNames('p-4 rounded-md w-full flex flex-col text-white', className)}>
            <div className={'flex flex-row items-center'}>
                {icon}
                <h1 className={'ml-2 text-lg'}>{title}</h1>
            </div>
            <p className={'mt-2 text-white'}>{children}</p>
            {extra}
        </div>
    )
}