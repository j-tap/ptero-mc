import http from '@/api/http';

export default (server: string, file: string): Promise<string> => {
    return http
        .get(`/api/client/servers/${server}/docs/contents`, {
            params: { file },
            responseType: 'text',
        })
        .then(({ data }) => data);
};
