import http from '@/api/http';

export type Gamemode = 'survival' | 'creative' | 'adventure' | 'spectator';

export default async (serverUuid: string, playerUuid: string, mode: Gamemode): Promise<void> => {
    await http.post(
        `/api/client/extensions/minecraftplayermanager/servers/${serverUuid}/gamemode`,
        { uuid: playerUuid, mode }
    );
};
