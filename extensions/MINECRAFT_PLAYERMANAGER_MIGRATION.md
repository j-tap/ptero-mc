# Minecraft Player Manager — перенос из .blueprint в проект

Расширение перенесено из `.blueprint/extensions/minecraftplayermanager` в структуру проекта.

## Где что лежит

| Было (.blueprint) | Стало (в проекте) |
|-------------------|-------------------|
| `app/` (PHP) | `app/BlueprintFramework/Extensions/minecraftplayermanager/` |
| `routers/client.php` | `routes/blueprint/client/minecraftplayermanager.php` |
| `components/` (React/TS) | `resources/scripts/blueprint/extensions/minecraftplayermanager/` |
| `private/skin.html` | `resources/views/extensions/minecraftplayermanager/skin.html` |

## Что сделано

1. **PHP** — симлинк `app/BlueprintFramework/Extensions/minecraftplayermanager` заменён на реальные файлы (контроллер, Request-классы, Dependencies, Status, Utilities, UserCache).
2. **Маршруты** — симлинк `routes/blueprint/client/minecraftplayermanager.php` заменён на обычный файл с теми же маршрутами.
3. **Фронтенд** — симлинк `resources/scripts/blueprint/extensions/minecraftplayermanager` заменён на реальные файлы (PlayerManagerContainer, PlayerRow, Banner, api/*).
4. **Скин** — `skin.html` скопирован в `resources/views/extensions/minecraftplayermanager/skin.html`, в контроллере используется `resource_path('views/extensions/minecraftplayermanager/skin.html')`.

Импорт во фронте не менялся: по-прежнему `@blueprint/extensions/minecraftplayermanager/PlayerManagerContainer`. Неймспейс PHP: `Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager`.

## После переноса

Выполните один раз:

```bash
composer dump-autoload
```

При необходимости пересоберите фронт:

```bash
yarn build
# или
npm run build
```

## Папка .blueprint

Папку `.blueprint/extensions/minecraftplayermanager` можно удалить после проверки, что всё работает. Либо оставить как резервную копию.
