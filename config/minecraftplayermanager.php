<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Skin Sources
    |--------------------------------------------------------------------------
    |
    | By default we use Mojang-based skin source:
    | - skin_url = "__mojang__" (resolved via Mojang SessionServer API)
    | - avatar_url = Crafatar by UUID
    |
    | If you set MINECRAFT_AVATAR_URL and/or MINECRAFT_SKIN_URL in .env,
    | they will override defaults.
    |
    | Placeholders:
    | - {uuid} UUID without dashes
    */
    'avatar_url' => env('MINECRAFT_AVATAR_URL', 'https://crafatar.com/avatars/{uuid}?size=256&overlay'),
    'skin_url' => env('MINECRAFT_SKIN_URL', '__mojang__'),
    /*
    | Used by the 3D skin viewer when Mojang (or MINECRAFT_SKIN_URL) yields no URL — e.g. offline UUIDs.
    | Placeholder: {uuid} = without dashes. Set MINECRAFT_SKIN_PREVIEW_FALLBACK_URL= to disable.
    */
    'skin_preview_fallback_url' => env('MINECRAFT_SKIN_PREVIEW_FALLBACK_URL', 'https://velithcraft.online/skin/{uuid}'),
    'authme' => [
        'table' => env('MINECRAFT_AUTHME_TABLE', 'authme'),
    ],

    /*
    | Optional: map player name → licensed (premium). When MINECRAFT_LICENSE_TABLE is set, a matching
    | row overrides UUID-based inference (v3 ≈ offline, v4 ≈ Mojang). Same DB discovery as AuthMe.
    */
    'license' => [
        'table' => env('MINECRAFT_LICENSE_TABLE', ''),
        'username_column' => env('MINECRAFT_LICENSE_USERNAME_COLUMN', 'username'),
        'licensed_column' => env('MINECRAFT_LICENSE_COLUMN', 'licensed'),
    ],

    /*
    | Optional custom item icon template (mods / own CDN). Merged in code with built-in vanilla URLs.
    | Placeholders: {path}, {path_uri}
    */
    'item_icons' => [
        'custom_template' => env('MINECRAFT_ITEM_ICON_CUSTOM_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | External statistics (optional)
    |--------------------------------------------------------------------------
    |
    | If set (http/https only), the player management page shows a button that
    | opens this URL in a new tab (e.g. Plan / community stats dashboard).
    |
    */
    'external_stats_url' => env('MINECRAFT_EXTERNAL_STATS_URL', ''),
];
