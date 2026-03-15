# План поэтапного внедрения раздела «Документация»

После полного отката все изменения по фиче документации убраны. Внедрять заново по шагам с проверкой после каждого этапа.

---

## Перед началом

1. Пересобрать фронт: `yarn run build:production`
2. Открыть страницу сервера и убедиться, что **нет ошибки «o is not a function»** и панель работает
3. Только после этого переходить к этапам ниже

---

## Этап 1: Конфиг и бэкенд API (без фронта)

**Сделать:**
- В `config/pterodactyl.php` добавить в секцию `files`: `'docs_path' => env('DOCS_PATH', 'docs')`
- Создать `app/Http/Requests/Api/Client/Servers/Docs/ListDocsRequest.php` и `GetDocContentsRequest.php` (валидация путей, только .md)
- Создать `app/Http/Controllers/Api/Client/Servers/ServerDocsController.php` (методы `list`, `contents`)
- В `routes/api-client.php` добавить группу `docs`: `GET .../docs/list`, `GET .../docs/contents?file=...`

**Проверка:**  
Вызвать в браузере или curl (с авторизацией):  
`GET /api/client/servers/{server}/docs/list` — должен вернуть JSON (список или пустой массив).  
Ошибка 404/500 — значит что-то не так на этом этапе.

---

## Этап 2: Фронт — только API и маршрут (без UI страницы)

**Сделать:**
- В `resources/scripts/api/server/docs/` добавить `loadDocsList.ts`, `getDocContents.ts`
- В `resources/scripts/plugins/` добавить `useDocsListSwr.ts`, `useDocContentSwr.ts`
- В `resources/scripts/routers/routes.ts` добавить один маршрут: path `'/docs'`, name `'Documentation'`, permission `'file.*'`, component — временно указать **существующий** компонент, например `ServerConsole` (чтобы не подключать новый код)

**Проверка:**  
Пересобрать фронт, открыть страницу сервера. Должны быть:
- Без ошибки «o is not a function»
- В меню сервера появился пункт «Documentation», по клику открывается консоль (временно)

Если ошибка появилась — проблема в добавлении маршрута или в API-клиенте/хуках.

---

## Этап 3: Страница просмотра документации

**Сделать:**
- Создать `resources/scripts/components/server/docs/DocsContainer.tsx` (список .md, просмотр через react-markdown, внутренние ссылки)
- В `routes.ts` заменить временный component на `DocsContainer` (сначала **прямой** import, без `lazy()`)

**Проверка:**  
Пересборка, заход в «Documentation». Должны открываться список файлов из `docs/` и просмотр выбранного .md. Если снова «o is not a function» — причина в `DocsContainer` или его зависимостях (например, `pathe`, `react-markdown`).

---

## Этап 4 (по желанию): Lazy-загрузка и доработки

- Заменить прямой import `DocsContainer` на `lazy(() => import('...'))` и проверить, что ошибки нет
- При необходимости: донастройка путей, якорей, стилей

---

## Что откатили (на будущее)

- `app/Http/Controllers/Base/LocaleController.php`
- `app/Http/ViewComposers/AssetComposer.php`
- `config/pterodactyl.php` (в т.ч. docs_path)
- `resources/scripts/api/server/getServer.ts`
- `resources/scripts/blueprint/extends/routers/ServerRouter.tsx`
- `resources/scripts/components/App.tsx`
- `resources/scripts/components/elements/Translate.tsx`
- `resources/scripts/components/server/features/Features.tsx`
- `resources/scripts/routers/ServerRouter.tsx`
- `resources/views/templates/wrapper.blade.php`
- `routes/api-client.php`
- Удалены: ServerDocsController, Docs Request-классы, api/server/docs/*, components/server/docs/*, useDocsListSwr, useDocContentSwr

Файлы `package.json` и `yarn.lock` не откатывали — при необходимости откат вручную: `git checkout HEAD -- package.json yarn.lock`.
