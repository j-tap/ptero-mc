import http from '@/api/http';

export default async (uuid: string, name: string): Promise<void> => {
    await http.put(`/api/client/extensions/minecraftplayermanager/servers/${uuid}/op`, {
        name,
    });
};
