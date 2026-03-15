import http from '@/api/http';

export interface DocSearchHit {
    path: string;
    snippet: string;
}

export default (uuid: string, q: string): Promise<DocSearchHit[]> => {
    return http
        .get(`/api/client/servers/${uuid}/docs/search`, { params: { q: q.trim() } })
        .then(({ data }) => data.data ?? []);
};
