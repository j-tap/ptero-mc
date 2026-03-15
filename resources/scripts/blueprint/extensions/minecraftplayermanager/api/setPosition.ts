import http from '@/api/http';

export default async (
    serverUuid: string,
    playerUuid: string,
    position: { x: number; y: number; z: number }
): Promise<void> => {
    await http.post(
        `/api/client/extensions/minecraftplayermanager/servers/${serverUuid}/position`,
        { uuid: playerUuid, ...position }
    );
};
