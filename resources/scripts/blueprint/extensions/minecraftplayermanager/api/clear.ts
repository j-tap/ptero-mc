import http from '@/api/http';

export default async (uuid: string, playerUuid: string): Promise<void> => {
    await http.post(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/clear`, {
        uuid: playerUuid,
    });
};
