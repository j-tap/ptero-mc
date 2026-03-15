export interface MCPlugin {
    id: number;
    name: string;
    external: boolean;
    testedVersions?: string[];
    tag?: string;
    updateDate: number;
    installed: boolean;
}

export interface InstalledPluginsParams {
    id?: number;
    server_uuid?: string;
    plugin_id: number;
    plugin_name: string;
    filename: string;
    disabled?: boolean;
    tag?: string;
    config_folder?: string | null;
    created_at?: Date;
    updated_at?: Date;
}
