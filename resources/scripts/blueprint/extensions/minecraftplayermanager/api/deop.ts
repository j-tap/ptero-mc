import http from '@/api/http';

export default async (uuid: string, playerUuid: string): Promise<void> => {
    await http.delete(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/op`, {
        data: {
            uuid: playerUuid,
        },
    });
};
