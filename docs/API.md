# API сервиса и подключение к Claude

Ключ создаёт владелец кабинета: **Настройки → API и подключение к Claude** (`/app/settings/api`). Ключ вида `lz_…` показывается один раз,
в базе хранится только его SHA-256. Доступ — **только чтение** данных этого кабинета. Отозвать ключ можно там же.

## MCP-сервер (Claude)

Адрес: `https://wb.linkoz.ru/mcp/<ключ>` — Model Context Protocol, транспорт Streamable HTTP (на каждый POST — JSON-ответ, без SSE).

- **claude.ai**: Настройки → Коннекторы → «Добавить пользовательский коннектор» → URL = адрес выше, аутентификация «Без входа».
  Или надёжнее: URL `https://wb.linkoz.ru/mcp`, в «Заголовки запроса» — `Authorization: Bearer <ключ>` (Claude хранит значение скрыто).
- **Claude Code**: `claude mcp add --transport http lenta-wb https://wb.linkoz.ru/mcp/<ключ>`

Поддерживаются `initialize`, `ping`, `tools/list`, `tools/call` (версии протокола 2025-06-18, 2025-03-26, 2024-11-05).
Проверено официальным клиентом `@modelcontextprotocol/sdk`.

## REST

`GET|POST https://wb.linkoz.ru/api/v1/<инструмент>` — параметры в строке запроса или в JSON-теле.
Ключ: `Authorization: Bearer <ключ>` (или заголовок `X-Api-Key`, или `?key=`). Ответ: `{"ok": true, "data": …}`.
`GET /api/v1` — список инструментов и их параметров.

```
curl -H "Authorization: Bearer lz_…" "https://wb.linkoz.ru/api/v1/finance_summary?period=2026-10"
```

## Инструменты

| Инструмент | Что возвращает | Параметры |
|---|---|---|
| `account_info` | Кабинет, тариф, магазины, категории токена, ошибки | — |
| `orders_summary` | KPI ленты, топ товаров, график | `period` (today, yesterday, 7d, 30d, custom), `from`, `to`, `store_id`, `scheme` |
| `list_orders` | Заказы с расчётом прибыли | то же + `status`, `q`, `limit` ≤ 200 |
| `products` | Себестоимость, комиссия, объём, остатки, заказы за 30 дней | `q`, `store_id`, `limit` ≤ 500 |
| `finance_summary` | Чистая прибыль по отчётам реализации, разбивка по неделям | `period` (w4, w12, all, ГГГГ-ММ, week:ГГГГ-ММ-ДД) или `from`/`to`, `store_id` |
| `finance_opiu` | ОПИУ по неделям/месяцам, расходы по статьям | то же + `group` (week, month) |
| `finance_sku` | Прибыль по товарам с ABC | то же + `sort`, `limit` |
| `finance_reports` | Отчёты WB и сверка «Итого к оплате» | `store_id`, `limit` |
| `finance_order` | Строки отчётов и расчёт по одному заказу | `srid` |
| `sync_status` | Cron, источники WB по магазинам, ошибки, журнал запросов | `store_id`, `log_limit` |
| `admin_overview` | Только для администратора: клиенты, тарифы, ошибки магазинов, платежи | `limit` |

Названия показателей финансов (`sales`, `revenue`, `commission`, `turnover`, `profit` …) — в поле `labels` ответа.
