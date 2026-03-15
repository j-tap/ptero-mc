<?php

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class PlayerManagerSetGamemodeRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_CONTROL_CONSOLE;
    }

    public function rules(): array
    {
        return [
            'uuid' => 'required|string|size:36',
            'mode' => 'required|string|in:survival,creative,adventure,spectator',
        ];
    }
}
