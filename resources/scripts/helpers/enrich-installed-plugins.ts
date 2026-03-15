import { InstalledPluginsParams, MCPlugin } from '@/types';

export type EnrichedInstalledPlugin = InstalledPluginsParams & {
    tag?: string;
    testedVersions?: string[];
    description?: string;
};

export function enrichInstalledPlugins(
    installed: InstalledPluginsParams[],
    available: MCPlugin[]
): EnrichedInstalledPlugin[] {
    return installed.map((installedPlugin) => {
        const match = available.find((p) => p.id === installedPlugin.plugin_id);

        const tag = match?.tag ?? installedPlugin.tag;
        return {
            ...installedPlugin,
            tag,
            testedVersions: match?.testedVersions,
            description: tag,
        };
    });
}
