import http from '@/api/http';
import type { InventoryItem } from './inventoryTypes';

export type { InventoryItem } from './inventoryTypes';

export type InventoryFetchResult = {
    inventory: InventoryItem[];
    item_icon_templates: string[];
};

export default async (uuid: string, playerUuid: string): Promise<InventoryFetchResult> => {
    const { data } = await http.get(
        `/api/client/extensions/minecraftplayermanager/servers/${uuid}/inventory/${playerUuid}`
    );

    const rawTemplates = data.item_icon_templates ?? data.itemIconTemplates;

    return {
        inventory: data.inventory ?? [],
        item_icon_templates: Array.isArray(rawTemplates)
            ? rawTemplates.filter((entry): entry is string => typeof entry === 'string' && entry.trim() !== '')
            : [],
    };
};
