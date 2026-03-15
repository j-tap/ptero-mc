<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Files;

use Pterodactyl\Models\Permission;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class SearchFilesRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_READ_CONTENT;
    }

    public function rules(): array
    {
        return [
            'directory' => 'sometimes|nullable|string',
            'q' => 'required|string|min:1|max:200',
        ];
    }

    protected function passedValidation(): void
    {
        $dir = (string) $this->input('directory', '/');
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if (str_contains($dir, '..')) {
            abort(422, 'Invalid directory path.');
        }
        $this->merge(['directory' => $dir === '' ? '/' : '/' . $dir]);
    }
}
