import http from '@/api/http';
import { Player } from './getStatus';

export type OfflinePlayer = Player & {
    playtime?: number | null;
    first_seen_at?: string | null;
    last_logout_at?: string | null;
};

export default async (uuid: string): Promise<OfflinePlayer[]> => {
    const { data } = await http.get(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/offline`);

    return data.players;
};
