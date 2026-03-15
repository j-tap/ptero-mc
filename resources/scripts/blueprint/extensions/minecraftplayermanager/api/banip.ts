import http from '@/api/http';

export default async (uuid: string, ip: string, reason: string): Promise<void> => {
    await http.put(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/ban-ip`, {
        ip, reason,
    });
};
