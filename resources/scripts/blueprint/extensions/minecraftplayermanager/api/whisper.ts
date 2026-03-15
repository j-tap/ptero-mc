import http from '@/api/http';

export default async (uuid: string, playerUuid: string, message: string): Promise<void> => {
    await http.post(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/whisper`, {
        uuid: playerUuid,
        message,
    });
};
