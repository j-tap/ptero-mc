export interface InventoryItem {
    slot: number;
    id: string;
    /** Resolved id for texture URLs (e.g. enchanted apple, shield with banner data). */
    icon_id?: string;
    count: number;
    displayName?: string;
}
