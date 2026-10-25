# Конспект по WB API (для разработки сервиса)

Источник — официальные спецификации OpenAPI с https://dev.wildberries.ru (скачаны в октябре 2026).
YAML-файлы разделов лежат по адресу `https://dev.wildberries.ru/api/swagger/yaml/ru/<раздел>.yaml`, например:
`01-general`, `03-orders-fbs`, `04-orders-dbw`, `05-dbs`, `06-in-store-pickup`, `07-orders-fbw`, `11-analytics`, `12-reports`, `13-finances`.

## Авторизация

- Токен создаётся в кабинете продавца: **Настройки → Доступ к API**. Передаётся в заголовке `Authorization: <токен>`.
- У токена есть **категории** (Контент, Аналитика, Цены и скидки, Маркетплейс, Статистика, Продвижение, Вопросы и отзывы, Чат, Поставки, Возвраты, Документы, Финансы, Пользователи) и признак «Только чтение».
- **Типы токенов** влияют на лимиты: Персональный, Сервисный, Базовый с секретом, Базовый. У «Базового» лимит Аналитики и Статистики — **1 запрос в 3 часа**, поэтому просим пользователей создавать «Персональный».
- Токен — это JWT. В payload есть `exp` (срок действия) и `sid` (ID продавца). Срок мы показываем в разделе «Магазины».
- `GET /ping` есть на каждом домене. Он проверяет токен и то, подходит ли его категория к сервису (лимит 3 запроса за 30 секунд). Так мы узнаём, какие категории у токена есть.
- `GET https://common-api.wildberries.ru/api/v1/seller-info` работает с токеном любой категории и возвращает `name`, `sid`, `tin`, `tradeMark`.

## Домены

| Сервис | Домен |
|---|---|
| Общее (seller-info, новости, тарифы) | `common-api.wildberries.ru` |
| Аналитика | `seller-analytics-api.wildberries.ru` |
| Статистика / отчёты | `statistics-api.wildberries.ru` |
| Маркетплейс (FBS, DBS, DBW, самовывоз) | `marketplace-api.wildberries.ru` (песочница: `marketplace-api-sandbox…`) |
| Контент | `content-api.wildberries.ru` |
| Финансы | `finance-api.wildberries.ru` |
| Поставки FBW | `supplies-api.wildberries.ru` |

> Старый домен `suppliers-api.wildberries.ru` больше не используется.

## Главный метод — «Лента заказов» (Аналитика)

`POST https://seller-analytics-api.wildberries.ru/api/analytics/v1/order-feed`

- Данные **в реальном времени**, все заказы продавца всех схем. 1 заказ = 1 сборочное задание = 1 единица товара.
- Не глубже **31 дня**. Период задаётся **по дате текущего статуса**; сортировка от новых к старым.
- Лимит: **1 запрос в минуту** (у Базового токена — 1 в 3 часа).

Запрос:
```json
{
  "selectedPeriod": { "start": "2026-07-23T00:00:00+03:00", "end": "…необязательно" },
  "nmIds": [], "subjectIds": [], "brandNames": [], "tagIds": [],
  "pagination": { "offset": 0, "limit": 5000, "snapshotTime": "…со 2-й страницы" }
}
```
`limit` — не больше 10 000. Со второй страницы обязательно передаётся `snapshotTime` из **первого** ответа, иначе возможны пропуски и дубли.

Ответ `data`: `snapshotTime`, `currency`, `orders[]`, где у заказа:
`nmId`, `chrtId`, `srid` (ID заказа), `createdAt`, `updatedAt` (время текущего статуса),
`status` (`created` | `buyout` | `cancel` | `return` | `returnDefective`),
`cancelType` (`app` — отказ до получения, `receipt` — при получении, `expire` — истёк срок, `other`),
`warehouseName` (для складов WB — пока просто «Склад WB»), `warehouseRegion`,
`isMp` (`true` — склад продавца, `false` — склад WB), `destinationCity`, `destinationDistrict`,
`sellerPrice` (цена продавца со скидкой продавца), `isB2b`.

**Как мы синхронизируем** (`src/Services/SyncService.php`): первый запрос берёт период, равный истории тарифа. Дальше запрашиваем с `snapshotTime − 15 минут` и делаем upsert по `srid`. Если страница заполнена целиком, следующую страницу запрашиваем через минуту с тем же `snapshotTime`.

## Сборочные задания (Маркетплейс): определение схемы

Лимит — 300 запросов в минуту на продавца. Ответ с кодом 4xx засчитывается как 10 запросов.

| Схема | Новые задания | Завершённые / все |
|---|---|---|
| FBS | `GET /api/v3/orders/new` | `GET /api/v3/orders?limit=&next=&dateFrom=<unix>&dateTo=<unix>` (до 30 дней) |
| DBS | `GET /api/v3/dbs/orders/new` | `GET /api/v3/dbs/orders` |
| DBW | `GET /api/v3/dbw/orders/new` | `GET /api/v3/dbw/orders` |
| Самовывоз | `GET /api/v3/click-collect/orders/new` | `GET /api/v3/click-collect/orders` |

Поля задания: `id` (№ сборочного задания), `rid` (**равен `srid`** в Ленте и Статистике — по нему мы склеиваем данные), `createdAt`, `nmId`, `chrtId`, `article`, `skus[]`, `price`, `salePrice`, `finalPrice` (**в копейках, ×100**), `warehouseId`, `cargoType`, `options.isB2B`, у FBS ещё `deliveryType`.
Статусы заданий: `POST /api/v3/orders/status` с телом `{ "orders": [id…] }` → `supplierStatus`, `wbStatus`.

## Статистика: обогащение

`GET https://statistics-api.wildberries.ru/api/v1/supplier/orders?dateFrom=<RFC3339>&flag=0`

- Обновляется раз в 30 минут, данные хранятся 90 дней, до 80 000 строк за ответ. Следующую порцию берите с `dateFrom = lastChangeDate` последней строки.
- Лимит: 1 запрос в минуту.
- Может не содержать заказы с неподтверждённой оплатой (поэтому основной источник — Лента).
- Поля: `date`, `lastChangeDate`, `warehouseName` (**реальный склад WB**), `warehouseType` («Склад WB» / «Склад продавца»), `regionName`, `oblastOkrugName`, `supplierArticle`, `nmId`, `barcode`, `category`, `subject`, `brand`, `techSize`, `totalPrice`, `discountPercent`, `spp`, `finishedPrice`, `priceWithDisc`, `isCancel`, `cancelDate`, `gNumber`, `srid`.
- Есть также `GET /api/v1/supplier/sales` (продажи и возвраты).

## Контент: названия и фото

`POST https://content-api.wildberries.ru/content/v2/get/cards/list`
```json
{ "settings": { "cursor": { "limit": 100 }, "filter": { "withPhoto": -1 } } }
```
Следующая страница: в `cursor` передаются `updatedAt` и `nmID` из ответа. Карточка содержит `nmID`, `vendorCode`, `title`, `brand`, `subjectName`, `photos[]` (`big`, `c246x328`, `c516x688`, `square`, `tm`).

## Лимиты и ошибки

- При 429 WB присылает заголовки `X-Ratelimit-Retry` (сколько секунд ждать), `X-Ratelimit-Remaining`, `X-Ratelimit-Limit`, `X-Ratelimit-Reset`.
- Тело ошибки (`application/problem+json`): `title`, `detail`, `code`, `requestId`, `origin`, `status`.
- 401 — токен недействителен; 403 — у токена нет нужной категории.

## Что ещё может пригодиться (идеи развития)

- Финансы: `POST /api/finance/v1/sales-reports/detailed` — детализация отчётов реализации (для расчёта прибыли).
- Остатки: `POST /api/analytics/v1/stocks-report/wb-warehouses`.
- Воронка продаж: `POST /api/analytics/v3/sales-funnel/products`.
- Возвраты: `returns-api.wildberries.ru`.
- Поставки FBW: `supplies-api.wildberries.ru`.
