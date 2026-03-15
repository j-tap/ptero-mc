<?php

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class PlayerManagerKickRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_CONTROL_CONSOLE;
    }

    public function rules(): array
    {
        return [
            'uuid' => 'required|string|size:36',
            'reason' => 'required|string|min:3|max:255',
        ];
    }
}
