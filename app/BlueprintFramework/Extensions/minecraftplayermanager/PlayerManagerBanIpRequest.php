<?php

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class PlayerManagerBanIpRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_CONTROL_CONSOLE;
    }

    public function rules(): array
    {
        return [
            'ip' => 'required|string|ip',
            'reason' => 'nullable|string|max:255',
        ];
    }
}
