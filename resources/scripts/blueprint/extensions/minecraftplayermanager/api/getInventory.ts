import http from '@/api/http';

export interface InventoryItem {
    slot: number;
    id: string;
    count: number;
    displayName?: string;
}

export default async (
    uuid: string,
    playerUuid: string
): Promise<InventoryItem[]> => {
    const { data } = await http.get(
        `/api/client/extensions/minecraftplayermanager/servers/${uuid}/inventory/${playerUuid}`
    );
    return data.inventory ?? [];
};
