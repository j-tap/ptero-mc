import http from '@/api/http';

export type Player = {
    name: string;
    uuid: string;
    avatar: string;
    /** Mojang vs offline heuristic (UUID v4/v3) or DB override when configured */
    licensed?: boolean | null;
};

export type OppedPlayer = Player & {
    level: number;
    bypassesPlayerLimit: boolean;
};

export type BannedPlayer = Player & {
    reason: string;
};

export type BannedIP = {
    ip: string;
    reason: string;
};

export default async (
    uuid: string
): Promise<
    | {
        ping: number;
        online: true;
        online_mode: boolean;
        is_proxy: boolean;
        is_proxied: boolean;
        /** Panel config; opens in new tab when set */
        external_stats_url?: string | null;
        opped: OppedPlayer[];

        banned: {
            players: BannedPlayer[];
            ips: BannedIP[];
        };

        whitelist: {
            enabled: boolean;
            list: Player[];
        };

        players: {
            online: number;
            max: number;
            list: Player[];
        };
    }
    | {
        online: false;
        online_mode: boolean;
        is_proxy: boolean;
        is_proxied: boolean;
        external_stats_url?: string | null;
        opped: OppedPlayer[];

        banned: {
            players: BannedPlayer[];
            ips: BannedIP[];
        };

        whitelist: {
            enabled: boolean;
            list: Player[];
        };
    }
> => {
    const { data } = await http.get(`/api/client/extensions/minecraftplayermanager/servers/${uuid}`);

    return data;
};
