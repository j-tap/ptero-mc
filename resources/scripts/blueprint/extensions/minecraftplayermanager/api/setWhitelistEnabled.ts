import http from '@/api/http';

export default async (uuid: string, enabled: boolean): Promise<void> => {
    await http.post(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/whitelist/status`, {
        enabled,
    });
};
