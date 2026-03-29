<?php

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Requests;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class PlayerManagerInventorySlotRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_CONTROL_CONSOLE;
    }

    public function rules(): array
    {
        return [
            'uuid' => 'required|string|size:36',
            'slot' => 'required|integer|min:0|max:255',
        ];
    }
}
