<?php

/**
 * Avatar and skin URL templates for Minecraft players.
 * Placeholder: {uuid} — UUID without dashes.
 *
 * Default: Minotar (Mojang skins).
 * For SkinsRestorer: set MINECRAFT_AVATAR_URL and MINECRAFT_SKIN_URL in .env
 * to your skin server/proxy URL, e.g. https://skins.example.com/helm/{uuid}/256.png
 */
return [
    'avatar_url' => env('MINECRAFT_AVATAR_URL', 'https://minotar.net/helm/{uuid}/256.png'),
    'skin_url' => env('MINECRAFT_SKIN_URL', 'https://minotar.net/skin/{uuid}'),
];
