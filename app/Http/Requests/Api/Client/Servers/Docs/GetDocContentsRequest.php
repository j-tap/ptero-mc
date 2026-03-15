<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Docs;

use Pterodactyl\Models\Permission;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class GetDocContentsRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_READ_CONTENT;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|string',
        ];
    }

    protected function passedValidation(): void
    {
        $file = trim(str_replace('\\', '/', $this->input('file')), '/');
        if ($file === '' || str_contains($file, '..')) {
            abort(422, 'Invalid file path.');
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['md', 'markdown'], true)) {
            abort(422, 'Only .md and .markdown files are allowed.');
        }
    }
}
