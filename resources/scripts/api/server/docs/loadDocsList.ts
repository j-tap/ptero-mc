import http from '@/api/http';
import { rawDataToFileObject } from '@/api/transformers';
import type { FileObject } from '@/api/server/files/loadDirectory';

export default async (uuid: string, directory = ''): Promise<FileObject[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/docs/list`, {
        params: directory ? { directory } : {},
    });
    return (data.data || []).map(rawDataToFileObject);
};
