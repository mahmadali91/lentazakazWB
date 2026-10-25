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

## Комиссии, тарифы складов, объём и остатки (используются в расчёте прибыли)

Раздел «Тарифы» — `https://dev.wildberries.ru/api/swagger/yaml/ru/10-rates.yaml`, «Товары» — `02-items.yaml`.

| Метод | Что даёт | Лимит |
|---|---|---|
| `GET common-api /api/v1/tariffs/commission?locale=ru` | Комиссия по каждому предмету: `paidStorageKgvp` — FBO, `kgvpMarketplace` — FBS, `kgvpSupplier` — DBS/DBW, `kgvpSupplierExpress` — EDBS, `kgvpPickup` — самовывоз, `kgvpBooking` — бронирование. Запрашивается токеном магазина, поэтому ставки соответствуют стране продавца | 1/мин (Базовый: 5/час) |
| `GET common-api /api/v1/tariffs/box?date=ГГГГ-ММ-ДД` | По складам: `boxDeliveryBase` / `boxDeliveryLiter` (логистика за 1-й / доп. литр с коэффициентом), `boxDeliveryCoefExpr` — коэффициент склада, %; то же для FBS (`boxDeliveryMarketplace*`). Числа приходят строками с запятой | 60/мин |
| `GET seller-analytics-api /api/v1/warehouse_remains?groupByNm=true` → `/tasks/{id}/status` → `/tasks/{id}/download` | Асинхронный отчёт об остатках: `volume` (объём, л), `warehouses[]` (склад, количество; служебные строки «В пути до получателей», «В пути возвраты на склад WB», «Всего находится на складах») | создание 1/мин, статус 1/5 сек, скачивание 1/мин |
| `POST content-api /content/v2/get/cards/list` | `subjectID` (для сопоставления с комиссией), `dimensions` (длина/ширина/высота, см → объём = Д×Ш×В/1000 л) | 100/мин |

### Формула логистики (инструкция WB «Доставка: виды и расчёт стоимости», 19.10.2026)

- Прямая доставка, до 1 л: 0,001–0,2 л — 23 ₽; 0,201–0,4 — 26 ₽; 0,401–0,6 — 29 ₽; 0,601–0,8 — 30 ₽; 0,801–1 — 32 ₽; × коэффициент склада.
- Прямая доставка, от 1 л: (46 ₽ + 14 ₽ × доп. литры) × коэффициент склада. С 15 августа для складов РФ коэффициент единый — 170% (для СГТ — 100%, сумма 1000–3000 ₽).
- Обратная доставка (отказ, возврат покупателем) — базовый тариф без коэффициента.
- Возврат в ПВЗ по FBS: до 1 л — 25 ₽; от 1 л — 25 ₽ + 4 ₽ × доп. литры.
- Примеры проверены в коде: 0,55 л × 170% = 49,3 ₽; 1,8 л × 170% = 97,24 ₽; обратная 1,8 л = 57,2 ₽.

## Финансы: отчёты реализации, хранение, реклама (раздел «Финансы»)

Раздел повторяет Excel-таблицу расчёта чистой прибыли («9.5»): лист «Исходник» — это детализация отчёта реализации, остальные листы — сводные.

| Метод | Что даёт | Лимит |
|---|---|---|
| `POST finance-api /api/finance/v1/sales-reports/list` `{dateFrom, dateTo, limit ≤ 1000, offset, period: weekly}` | Список отчётов: `reportId`, `dateFrom`/`dateTo`, `reportType` (1 — основной, 2 — по выкупам), суммы строками: `retailAmountSum`, `forPaySum`, `deliveryServiceSum`, `paidStorageSum`, `paidAcceptanceSum`, `deductionSum`, `penaltySum`, `additionalPaymentSum`, `bankPaymentSum` (итого к оплате) | 1/мин, только Персональный/Сервисный токен; данные с 29.01.2024 |
| `POST finance-api /api/finance/v1/sales-reports/detailed/{reportId}` `{limit ≤ 100000, rrdId, fields[]}` | Строки отчёта (как в файле из кабинета): `docTypeName`, `sellerOperName` (обоснование), `retailPriceWithDisc`, `retailAmount`, `forPay`, `acquiringFee`, `deliveryService`, `deliveryAmount`/`returnAmount`, `bonusTypeName`, `paidStorage`, `paidAcceptance`, `penalty`, `deduction`, `additionalPayment`, `cashback*`, `srid`, `shkId`… Деньги — строками. Следующая порция — с `rrdId` последней строки, пустой ответ (204) — конец | 1/мин |
| `GET seller-analytics-api /api/v1/paid_storage?dateFrom&dateTo` (≤ 8 дней) → `/tasks/{id}/status` → `/tasks/{id}/download` | Платное хранение по дням: `date`, `nmId`, `vendorCode`, `warehouse`, `volume`, `barcodesCount`, `warehousePrice` (₽ за день) | 1/мин |
| `GET advert-api /adv/v1/upd?from&to` (≤ 31 дня) | Списания за рекламу: `advertId`, `campName`, `updTime`, `updSum`, `paymentType` | 1/сек |
| `GET advert-api /api/advert/v2/adverts?ids=` (≤ 50) | Кампании и их товары: `nm_settings[].nm_id` | 5/сек |

**Как считаем** (`src/Services/Finance.php`, формулы вычисляемых столбцов листа «Исходник»):

- Продажи — `retailPriceWithDisc` для обоснований «Продажа», «Корректная продажа», «Сторно возвратов», «Коррекция возвратов»; для компенсаций WB (брак, потеря, подмена, коррекция продаж, возмещение издержек) — `forPay`. Возвраты — то же для документов «Возврат». Выручка = продажи − возвраты.
- Комиссия WB = ±(`retailPriceWithDisc` − `forPay` − `acquiringFee`), для компенсаций 0. Эквайринг — `acquiringFee` со знаком документа. Поэтому Выручка − Комиссия − Эквайринг = к перечислению за товар.
- Логистика — `deliveryService`; хранение, приёмка, штрафы, удержания, доплаты — из своих столбцов; баллы лояльности — `cashbackAmount + cashbackCommissionChange − cashbackDiscount`.
- Налоговая база — `retailAmount` (± по документу), для компенсаций — `forPay`; НДС = база / (1 + ставка) × ставка; налог = (база − НДС) × ставка налога.
- Себестоимость: + за продажу и компенсацию брака/потери, − за возврат, 0 для «Коррекция продаж».
- Оборот (придёт от WB) = Выручка − Комиссия − Эквайринг − Логистика − Лояльность. Чистая прибыль = Оборот − хранение и прочие удержания − себестоимость − налог − НДС − реклама − прочие расходы − самовыкупы.
- Самовыкупы (список Srid): выручка не считается продажей, расход = `retailAmount − forPay` + логистика + налог + доп. затраты; себестоимость не списывается.
- Сверка: Σ±`forPay` − логистика − хранение − приёмка − штрафы − удержания − доплаты − лояльность = `bankPaymentSum` («Итого к оплате»). Проверено на реальном отчёте: 640 233,41 ₽ — совпало.
- ABC: накопленная доля до 80% — A, до 95% — B, остальное — C (по выручке и по прибыли).
