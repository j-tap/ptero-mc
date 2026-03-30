import http from '@/api/http';
import { Player } from './getStatus';

export type OfflinePlayer = Player & {
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

export type OfflineResponse = {
    players: OfflinePlayer[];
    warnings?: string[];
};

export default async (uuid: string): Promise<OfflineResponse> => {
    const { data } = await http.get(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/offline`);

    return {
        players: data.players ?? [],
        warnings: data.warnings ?? [],
    };
};
