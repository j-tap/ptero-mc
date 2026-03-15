<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Docs;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class ListDocsRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_READ;
    }

    public function rules(): array
    {
        return [
            'directory' => 'sometimes|nullable|string',
        ];
    }

    protected function passedValidation(): void
    {
        $dir = (string) $this->input('directory', '');
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if ($dir !== '' && str_contains($dir, '..')) {
            abort(422, 'Invalid directory path.');
        }
        $this->merge(['directory' => $dir]);
    }
}
