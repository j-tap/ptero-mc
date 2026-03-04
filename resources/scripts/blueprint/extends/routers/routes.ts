import React from 'react';

/* blueprint/import *//* MinecraftplayermanagerImportStart */import MinecraftplayermanagerGzbjrxeotv from '@blueprint/extensions/minecraftplayermanager/PlayerManagerContainer';/* MinecraftplayermanagerImportEnd */

interface RouteDefinition {
  path: string;
  name: string | undefined;
  component: React.ComponentType;
  exact?: boolean;
  adminOnly: boolean | false;
  identifier: string;
}
interface ServerRouteDefinition extends RouteDefinition {
  permission: string | string[] | null;
}
interface Routes {
  account: RouteDefinition[];
  server: ServerRouteDefinition[];
}

export default {
  account: [
    /* routes/account *//* MinecraftplayermanagerAccountRouteStart *//* MinecraftplayermanagerAccountRouteEnd */
  ],
  server: [
    /* routes/server *//* MinecraftplayermanagerServerRouteStart */{ path: '/minecraft/players', permission: 'control.console', name: 'Players', component: MinecraftplayermanagerGzbjrxeotv, adminOnly: false, identifier: 'minecraftplayermanager' },/* MinecraftplayermanagerServerRouteEnd */
  ],
} as Routes;
