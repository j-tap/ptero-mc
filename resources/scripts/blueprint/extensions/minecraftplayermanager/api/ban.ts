import http from '@/api/http';

export default async (uuid: string, name: string, reason: string): Promise<void> => {
    await http.put(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/ban`, {
        name, reason,
    });
};
