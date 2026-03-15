import http from '@/api/http';

export default async (uuid: string, ip: string): Promise<void> => {
    await http.delete(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/ban-ip`, {
        data: {
            ip,
        },
    });
};
