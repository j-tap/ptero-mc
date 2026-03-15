import http from '@/api/http';

export interface FileSearchHit {
    path: string;
    snippet: string;
}

export default (uuid: string, directory: string, q: string): Promise<FileSearchHit[]> => {
    return http
        .get(`/api/client/servers/${uuid}/files/search`, {
            params: { directory: directory || '/', q: q.trim() },
        })
        .then(({ data }) => data.data ?? []);
};
