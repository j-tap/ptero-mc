import useSWR from 'swr';
import getDocContents from '@/api/server/docs/getDocContents';
import { ServerContext } from '@/state/server';

export const getDocContentSwrKey = (uuid: string, file: string): string =>
    `${uuid}:docs:content:${file}`;

export default (file: string | null) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    return useSWR<string | null>(
        uuid && file ? getDocContentSwrKey(uuid, file) : null,
        () => (uuid && file ? getDocContents(uuid, file) : Promise.resolve(null)),
        {
            revalidateOnMount: true,
            errorRetryCount: 1,
        }
    );
};
