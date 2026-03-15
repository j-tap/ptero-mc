import http from '@/api/http';

export default async (
    uuid: string,
    playerUuid: string
): Promise<{
    position?: {
        x: number;
        y: number;
        z: number;
    };

    world?: string;
    gamemode?: string;

    playtime?: number;
    deaths?: number;
    kills?: number;
    xp_total?: number;
    xp_level?: number;
    last_death?: number;
}> => {
    const { data } = await http.get(
        `/api/client/extensions/minecraftplayermanager/servers/${uuid}/stats/${playerUuid}`
    );

    return data.stats;
};
