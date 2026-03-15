import http from '@/api/http';
import { Player } from './getStatus';

export default async (uuid: string): Promise<Player[]> => {
    const { data } = await http.get(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/offline`);

    return data.players;
};
