import useSWR from 'swr';
import loadDocsList from '@/api/server/docs/loadDocsList';
import type { FileObject } from '@/api/server/files/loadDirectory';
import { ServerContext } from '@/state/server';

export const getDocsListSwrKey = (uuid: string, directory: string): string =>
    `${uuid}:docs:list:${directory}`;

export default (directory = '') => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    return useSWR<FileObject[]>(
        uuid ? getDocsListSwrKey(uuid, directory) : null,
        () => (uuid ? loadDocsList(uuid, directory) : Promise.resolve([])),
        {
            revalidateOnMount: true,
            errorRetryCount: 2,
        }
    );
};
