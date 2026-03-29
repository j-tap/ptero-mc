import http from '@/api/http';

export default async (serverUuid: string, playerUuid: string, slot: number): Promise<void> => {
    await http.delete(`/api/client/extensions/minecraftplayermanager/servers/${serverUuid}/inventory`, {
        data: { uuid: playerUuid, slot },
    });
};
