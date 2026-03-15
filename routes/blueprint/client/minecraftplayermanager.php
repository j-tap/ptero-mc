<?php

use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager;

Route::group([
	'prefix' => '/servers/{server}',
	'middleware' => [
		ServerSubject::class,
		AuthenticateServerAccess::class,
		ResourceBelongsToServer::class,
	],
], function () {
	Route::get('/', [minecraftplayermanager\PlayerManagerController::class, 'index']);
	Route::get('/skin', [minecraftplayermanager\PlayerManagerController::class, 'skin']);
	Route::get('/skinTexture', [minecraftplayermanager\PlayerManagerController::class, 'skinTexture']);
	Route::get('/offline', [minecraftplayermanager\PlayerManagerController::class, 'offline']);
	Route::get('/stats/{uuid}', [minecraftplayermanager\PlayerManagerController::class, 'stats']);
	Route::get('/inventory/{uuid}', [minecraftplayermanager\PlayerManagerController::class, 'inventory']);

	Route::post('/whitelist/status', [minecraftplayermanager\PlayerManagerController::class, 'setwhitelist']);
	Route::put('/whitelist', [minecraftplayermanager\PlayerManagerController::class, 'addwhitelist']);
	Route::delete('/whitelist', [minecraftplayermanager\PlayerManagerController::class, 'removewhitelist']);

	Route::put('/op', [minecraftplayermanager\PlayerManagerController::class, 'op']);
	Route::delete('/op', [minecraftplayermanager\PlayerManagerController::class, 'deop']);

	Route::put('/ban', [minecraftplayermanager\PlayerManagerController::class, 'ban']);
	Route::delete('/ban', [minecraftplayermanager\PlayerManagerController::class, 'unban']);
	Route::put('/ban-ip', [minecraftplayermanager\PlayerManagerController::class, 'banIp']);
	Route::put('/ban-ip-player', [minecraftplayermanager\PlayerManagerController::class, 'banIpPlayer']);
	Route::delete('/ban-ip', [minecraftplayermanager\PlayerManagerController::class, 'unbanIp']);

	Route::post('/kick', [minecraftplayermanager\PlayerManagerController::class, 'kick']);
	Route::post('/clear', [minecraftplayermanager\PlayerManagerController::class, 'clear']);
	Route::post('/wipe', [minecraftplayermanager\PlayerManagerController::class, 'wipe']);
	Route::post('/kill', [minecraftplayermanager\PlayerManagerController::class, 'kill']);
});
