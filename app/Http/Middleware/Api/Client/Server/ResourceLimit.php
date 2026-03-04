<?php

namespace Pterodactyl\Http\Middleware\Api\Client\Server;

use Closure;

class ResourceLimit
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}