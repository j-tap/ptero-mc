import http from '@/api/http';

export default async (serverUuid: string, playerUuid: string, level: number): Promise<void> => {
    await http.post(
        `/api/client/extensions/minecraftplayermanager/servers/${serverUuid}/level`,
        { uuid: playerUuid, level }
    );
};
