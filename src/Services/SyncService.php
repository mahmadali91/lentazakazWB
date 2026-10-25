<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Wb\WbClient;
use App\Wb\WbException;

/**
 * Синхронизация заказов магазина с WB. Запускается из cron/sync.php каждую минуту.
 *
 * Источники (каждый со своим расписанием и с учётом лимитов WB):
 *  1. feed  — Лента заказов (Аналитика): реальное время, все схемы, статусы выкуп/отмена/возврат. 1 запрос/мин.
 *  2. mp    — новые сборочные задания FBS/DBS/DBW/Самовывоз (Маркетплейс): моментально, уточняет схему.
 *  3. stats — заказы из Статистики: бренд, предмет, артикул, реальный склад WB. Раз в 30 минут.
 *  4. cards — карточки товаров (Контент): названия и фото. Раз в 6 часов.
 *  5. tariffs, remains — комиссии WB, тарифы складов, остатки и объём товаров.
 *  6. fin, storage, ads — отчёты реализации, платное хранение и реклама для раздела «Финансы» (платные тарифы).
 */
final class SyncService
{
    private const FEED_LIMIT = 5000;
    private const ASSEMBLY_SCHEMES = ['FBS', 'DBS', 'DBW', 'CC'];

    private array $store;
    private array $plan;
    private WbClient $wb;
    private array $scopes;
    private bool $silent;

    public function __construct(array $store, array $plan)
    {
        $this->store = $store;
        $this->plan = $plan;
        $this->wb = new WbClient(Crypto::decrypt($store['token_enc']));
        $this->scopes = json_decode((string) $store['scopes'], true) ?: [];
        // При первичной загрузке не шлём уведомления о старых заказах
        $this->silent = !(int) $store['initial_done'];
    }

    public function run(): array
    {
        $done = [];
        $now = time();
        $syncEvery = max(1, (int) ($this->plan['sync_minutes'] ?? 1)) * 60;

        if (!empty($this->scopes['analytics']) && $this->due('feed_next_at', $now)) {
            $done['feed'] = $this->safe('feed', fn() => $this->syncFeed($syncEvery));
        }
        if (!empty($this->scopes['marketplace']) && $this->due('mp_next_at', $now)) {
            $done['mp'] = $this->safe('mp', fn() => $this->syncAssembly($syncEvery));
        }
        if (!empty($this->scopes['statistics']) && $this->due('stats_next_at', $now)) {
            $done['stats'] = $this->safe('stats', fn() => $this->syncStats());
        }
        if (!empty($this->scopes['content']) && $this->due('cards_next_at', $now)) {
            $done['cards'] = $this->safe('cards', fn() => $this->syncCards());
        }
        if ($this->due('tariffs_next_at', $now)) {
            $done['tariffs'] = $this->safe('tariffs', fn() => $this->syncTariffs());
        }
        if (!empty($this->scopes['analytics']) && (int) $this->store['initial_done'] && $this->due('remains_next_at', $now)) {
            $done['remains'] = $this->safe('remains', fn() => $this->syncRemains());
        }

        if (!empty($this->plan['profit']) && (int) $this->store['initial_done']) {
            $fin = new FinanceSync($this->store, $this->wb);
            if (($this->scopes['finance'] ?? null) !== false && $this->due('fin_next_at', $now)) {
                $done['fin'] = $this->safe('fin', fn() => $fin->reports());
            }
            if (!empty($this->scopes['analytics']) && ($this->scopes['storage'] ?? null) !== false && $this->due('storage_next_at', $now)) {
                $done['storage'] = $this->safe('storage', fn() => $fin->storage());
            }
            if (($this->scopes['advert'] ?? null) !== false && $this->due('ads_next_at', $now)) {
                $done['ads'] = $this->safe('ads', fn() => $fin->ads());
            }
        }

        if (!(int) $this->store['initial_done'] && $this->initialComplete()) {
            DB::exec('UPDATE stores SET initial_done = 1 WHERE id = ?', [$this->store['id']]);
        }
        return $done;
    }

    private function due(string $column, int $now): bool
    {
        $v = $this->store[$column] ?? null;
        return $v === null || strtotime((string) $v) <= $now;
    }

    private function setNext(string $column, int $seconds): void
    {
        $at = date('Y-m-d H:i:s', time() + $seconds);
        $this->store[$column] = $at;
        DB::exec("UPDATE stores SET $column = ? WHERE id = ?", [$at, $this->store['id']]);
    }

    /** Выполняет шаг синхронизации, ловит ошибки WB и откладывает следующий запрос. */
    private function safe(string $source, callable $fn): string
    {
        $column = [
            'feed' => 'feed_next_at', 'mp' => 'mp_next_at', 'stats' => 'stats_next_at', 'cards' => 'cards_next_at',
            'tariffs' => 'tariffs_next_at', 'remains' => 'remains_next_at',
            'fin' => 'fin_next_at', 'storage' => 'storage_next_at', 'ads' => 'ads_next_at',
        ][$source];
        // Справочные и финансовые источники не должны отключать ленту и пугать ошибками
        $auxiliary = in_array($source, ['tariffs', 'remains', 'fin', 'storage', 'ads'], true);
        // Флаг в scopes для финансовых источников: false — WB не дал доступ, раздел «Финансы» покажет подсказку
        $flag = ['fin' => 'finance', 'storage' => 'storage', 'ads' => 'advert'][$source] ?? null;
        try {
            $rows = (int) $fn();
            $this->log($source, true, $rows, null);
            if ($flag && empty($this->scopes[$flag])) {
                $this->scopes[$flag] = true;
                unset($this->scopes[$flag . '_error']);
                DB::exec('UPDATE stores SET scopes = ? WHERE id = ?', [json_encode($this->scopes), $this->store['id']]);
            }
            $basicTokenWarning = str_contains((string) $this->store['last_error'], '«Базовый»');
            if (!$auxiliary && $this->store['last_error'] && (!$basicTokenWarning || in_array($source, ['feed', 'stats'], true))) {
                DB::exec('UPDATE stores SET last_error = NULL, last_error_at = NULL WHERE id = ?', [$this->store['id']]);
                $this->store['last_error'] = null;
            }
            return "ok:$rows";
        } catch (WbException $e) {
            $delay = match (true) {
                $e->isRateLimit()   => max(61, $e->retryAfter + 1),
                $e->isAuthError()   => 3600,
                $e->status >= 500   => 120,
                default             => 300,
            };
            $this->setNext($column, $delay);
            if ($auxiliary) {
                $this->setNext($column, match (true) {
                    $e->isRateLimit() => max(61, $e->retryAfter + 1),
                    $flag !== null && ($e->status >= 500 || $e->status === 0) => 600,
                    default => 6 * 3600,
                });
                if ($source === 'remains') {
                    DB::exec('UPDATE stores SET remains_state = NULL WHERE id = ?', [$this->store['id']]);
                }
                if ($source === 'storage') {
                    $st = json_decode((string) ($this->store['storage_state'] ?? ''), true) ?: [];
                    unset($st['task']);
                    DB::exec('UPDATE stores SET storage_state = ? WHERE id = ?', [json_encode($st), $this->store['id']]);
                }
                if ($flag && $e->isAuthError()) {
                    $this->scopes[$flag] = false;
                    $this->scopes[$flag . '_error'] = mb_substr($e->status . ' ' . $e->getMessage(), 0, 300);
                    DB::exec('UPDATE stores SET scopes = ? WHERE id = ?', [json_encode($this->scopes), $this->store['id']]);
                }
                $this->log($source, false, 0, $e->status . ' ' . $e->getMessage());
                return 'error:' . $e->status;
            }
            if ($e->isAuthError()) {
                // Токен отозван или у него нет категории — отключаем источник, пользователь увидит предупреждение
                $this->scopes[$this->scopeOf($source)] = false;
                DB::exec('UPDATE stores SET scopes = ? WHERE id = ?', [json_encode($this->scopes), $this->store['id']]);
            }
            if (!$e->isRateLimit()) {
                $msg = mb_substr($e->humanMessage(), 0, 480);
                DB::exec('UPDATE stores SET last_error = ?, last_error_at = ? WHERE id = ?', [$msg, now(), $this->store['id']]);
            } elseif ($e->retryAfter > 3600 && in_array($source, ['feed', 'stats'], true)) {
                // Лимит в часы на Аналитику/Статистику бывает только у «Базового» токена (1 запрос в 3 часа)
                $msg = 'WB разрешает этому токену запрашивать заказы только раз в 3 часа — у него тип «Базовый». '
                    . 'Создайте в кабинете WB токен типа «Персональный» (категории Аналитика, Статистика, Маркетплейс, Контент, Финансы, Продвижение) '
                    . 'и замените его здесь: «Управление магазином» → «Новый токен WB». Следующее обновление: ' . date('H:i', time() + $e->retryAfter) . '.';
                DB::exec('UPDATE stores SET last_error = ?, last_error_at = ? WHERE id = ?', [$msg, now(), $this->store['id']]);
                $this->store['last_error'] = $msg;
            }
            $this->log($source, false, 0, $e->status . ' ' . $e->getMessage());
            return 'error:' . $e->status;
        } catch (\Throwable $e) {
            $this->setNext($column, 300);
            $this->log($source, false, 0, $e->getMessage());
            log_message('sync', "store {$this->store['id']} $source: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return 'error';
        }
    }

    private function scopeOf(string $source): string
    {
        return ['feed' => 'analytics', 'mp' => 'marketplace', 'stats' => 'statistics', 'cards' => 'content', 'remains' => 'analytics', 'tariffs' => 'common',
            'fin' => 'finance', 'storage' => 'storage', 'ads' => 'advert'][$source];
    }

    private function log(string $source, bool $ok, int $rows, ?string $message): void
    {
        DB::insert('sync_log', [
            'store_id'   => $this->store['id'],
            'source'     => $source,
            'ok'         => $ok ? 1 : 0,
            'rows_count' => $rows,
            'message'    => $message !== null ? mb_substr($message, 0, 490) : null,
            'created_at' => now(),
        ]);
    }

    private function historyStart(): int
    {
        $days = min(30, max(1, (int) ($this->plan['history_days'] ?? 3)));
        return time() - $days * 86400;
    }

    // ------------------------------------------------------------------ 1. Лента заказов

    private function syncFeed(int $syncEvery): int
    {
        $state = json_decode((string) ($this->store['feed_state'] ?? ''), true) ?: [];
        $paging = $state['paging'] ?? null;

        if ($paging) {
            $start = (string) $paging['start'];
            $offset = (int) $paging['offset'];
            $snapshot = (string) $paging['snapshot'];
        } else {
            $minStart = time() - 30 * 86400;
            $high = !empty($state['high']) ? strtotime((string) $state['high']) : false;
            $from = $high ? $high - 15 * 60 : $this->historyStart();
            $start = date('c', max($minStart, min($from, time() - 60)));
            $offset = 0;
            $snapshot = null;
        }

        $requestedAt = time();
        $data = $this->wb->orderFeed($start, $offset, self::FEED_LIMIT, $snapshot);
        $orders = $data['orders'] ?? [];
        $count = 0;
        $seenNm = [];
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($orders as $o) {
                if (empty($o['srid'])) {
                    continue;
                }
                OrderRepository::upsertFromFeed((int) $this->store['id'], $o, $this->silent);
                if (!empty($o['nmId']) && !isset($seenNm[$o['nmId']])) {
                    $seenNm[$o['nmId']] = true;
                    OrderRepository::rememberProduct((int) $this->store['id'], (int) $o['nmId'], null);
                }
                $count++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // WB может прислать snapshotTime пустым — тогда отсчитываем от момента запроса (иначе каждый раз грузилась бы вся история)
        if ($snapshot === null || $snapshot === '' || strtotime($snapshot) === false) {
            $snapshot = (string) ($data['snapshotTime'] ?? '');
            if ($snapshot === '' || strtotime($snapshot) === false) {
                $snapshot = date('c', $requestedAt);
            }
        }
        if (count($orders) >= self::FEED_LIMIT) {
            // Есть ещё страницы — продолжим через минуту (лимит WB) с тем же snapshotTime
            $state['paging'] = ['start' => $start, 'offset' => $offset + self::FEED_LIMIT, 'snapshot' => $snapshot];
            $next = 61;
        } else {
            unset($state['paging']);
            $state['high'] = $snapshot;
            $state['done'] = true;
            $next = $syncEvery;
        }
        DB::exec('UPDATE stores SET feed_state = ?, feed_ok_at = ? WHERE id = ?', [json_encode($state), now(), $this->store['id']]);
        $this->store['feed_state'] = json_encode($state);
        $this->setNext('feed_next_at', $next);
        return $count;
    }

    // ------------------------------------------------------------------ 2. Сборочные задания

    private function syncAssembly(int $syncEvery): int
    {
        $count = 0;
        $available = $this->scopes['mp_schemes'] ?? self::ASSEMBLY_SCHEMES;
        $stillAvailable = [];
        foreach (self::ASSEMBLY_SCHEMES as $scheme) {
            if (!in_array($scheme, $available, true)) {
                continue;
            }
            try {
                $orders = $this->wb->newAssemblyOrders($scheme);
            } catch (WbException $e) {
                // Схема не подключена у продавца — больше не опрашиваем её (пересчитается при проверке токена)
                if (in_array($e->status, [400, 404], true)) {
                    continue;
                }
                throw $e;
            }
            $stillAvailable[] = $scheme;
            foreach ($orders as $o) {
                if (empty($o['rid'])) {
                    continue;
                }
                OrderRepository::upsertFromAssembly((int) $this->store['id'], $scheme, $o, $this->silent);
                OrderRepository::rememberProduct((int) $this->store['id'], isset($o['nmId']) ? (int) $o['nmId'] : null, $o['article'] ?? null);
                $count++;
            }
        }
        if ($stillAvailable !== $available) {
            $this->scopes['mp_schemes'] = $stillAvailable;
            DB::exec('UPDATE stores SET scopes = ? WHERE id = ?', [json_encode($this->scopes), $this->store['id']]);
        }
        DB::exec('UPDATE stores SET mp_ok_at = ? WHERE id = ?', [now(), $this->store['id']]);
        $this->setNext('mp_next_at', $syncEvery);
        return $count;
    }

    // ------------------------------------------------------------------ 3. Статистика

    private function syncStats(): int
    {
        $cursor = $this->store['stats_cursor'] ?: date('Y-m-d\TH:i:s', $this->historyStart());
        $rows = $this->wb->supplierOrders($cursor);
        $count = 0;
        $last = $cursor;
        $seen = [];
        // Уведомляем по Статистике только если Ленты заказов нет (токен без «Аналитики»)
        $silent = $this->silent || !empty($this->scopes['analytics']);
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $o) {
                if (empty($o['srid'])) {
                    continue;
                }
                OrderRepository::upsertFromStats((int) $this->store['id'], $o, $silent);
                if (!empty($o['nmId']) && !isset($seen[$o['nmId']])) {
                    $seen[$o['nmId']] = true;
                    OrderRepository::rememberProduct((int) $this->store['id'], (int) $o['nmId'], $o['supplierArticle'] ?? null, $o['brand'] ?? null, $o['subject'] ?? null);
                }
                if (!empty($o['lastChangeDate'])) {
                    $last = $o['lastChangeDate'];
                }
                $count++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        DB::exec('UPDATE stores SET stats_cursor = ?, stats_ok_at = ? WHERE id = ?', [$last, now(), $this->store['id']]);
        $this->store['stats_cursor'] = $last;
        // Данные Статистики обновляются раз в 30 минут; большая выгрузка — догружаем через минуту
        $this->setNext('stats_next_at', $count >= 80000 ? 61 : (empty($this->scopes['analytics']) ? 600 : 1800));
        return $count;
    }

    // ------------------------------------------------------------------ 4. Карточки

    private function syncCards(): int
    {
        $count = 0;
        $cursor = null;
        for ($page = 0; $page < 20; $page++) {
            $resp = $this->wb->cardsList(100, $cursor);
            $cards = $resp['cards'] ?? [];
            foreach ($cards as $card) {
                if (!empty($card['nmID'])) {
                    OrderRepository::upsertProduct((int) $this->store['id'], $card);
                    $count++;
                }
            }
            $cursor = $resp['cursor'] ?? null;
            if (count($cards) < 100 || !$cursor) {
                break;
            }
            usleep(700000);
        }
        $this->setNext('cards_next_at', 6 * 3600);
        return $count;
    }

    // ------------------------------------------------------------------ 5. Комиссии и тарифы складов

    /** Комиссии WB по предметам для этого продавца (раз в сутки) и тарифы складов (общие, раз в 6 часов). */
    private function syncTariffs(): int
    {
        $rows = $this->wb->commissions();
        $storeId = (int) $this->store['id'];
        $pct = static fn($v) => is_numeric($v) ? round((float) $v, 2) : null;
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::exec('DELETE FROM wb_commissions WHERE store_id = ?', [$storeId]);
            $stmt = $pdo->prepare('INSERT INTO wb_commissions (store_id, subject_id, subject_name, parent_name, fbo, fbs, dbs, edbs, pickup, booking, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name)');
            foreach ($rows as $c) {
                if (empty($c['subjectID'])) {
                    continue;
                }
                $stmt->execute([
                    $storeId, (int) $c['subjectID'], mb_substr((string) ($c['subjectName'] ?? ''), 0, 160), mb_substr((string) ($c['parentName'] ?? ''), 0, 160),
                    $pct($c['paidStorageKgvp'] ?? null), $pct($c['kgvpMarketplace'] ?? null), $pct($c['kgvpSupplier'] ?? null),
                    $pct($c['kgvpSupplierExpress'] ?? null), $pct($c['kgvpPickup'] ?? null), $pct($c['kgvpBooking'] ?? null), now(),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Тарифы коробов одинаковы для всех продавцов — обновляем раз в 6 часов любым токеном
        $boxAt = DB::setting('box_tariffs_at');
        if (!$boxAt || strtotime($boxAt) < time() - 6 * 3600) {
            $num = static fn($v) => is_numeric($v = str_replace([',', ' '], ['.', ''], (string) $v)) ? (float) $v : null;
            foreach ($this->wb->boxTariffs(date('Y-m-d')) as $w) {
                if (empty($w['warehouseName'])) {
                    continue;
                }
                DB::exec('INSERT INTO wb_box_tariffs (warehouse_name, geo_name, delivery_base, delivery_liter, delivery_coef, mp_base, mp_liter, mp_coef, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE geo_name = VALUES(geo_name), delivery_base = VALUES(delivery_base),
                    delivery_liter = VALUES(delivery_liter), delivery_coef = VALUES(delivery_coef), mp_base = VALUES(mp_base),
                    mp_liter = VALUES(mp_liter), mp_coef = VALUES(mp_coef), updated_at = VALUES(updated_at)', [
                    mb_substr($w['warehouseName'], 0, 160), $w['geoName'] ?? null, $num($w['boxDeliveryBase'] ?? null), $num($w['boxDeliveryLiter'] ?? null),
                    $num($w['boxDeliveryCoefExpr'] ?? null), $num($w['boxDeliveryMarketplaceBase'] ?? null), $num($w['boxDeliveryMarketplaceLiter'] ?? null),
                    $num($w['boxDeliveryMarketplaceCoefExpr'] ?? null), now(),
                ]);
            }
            DB::setSetting('box_tariffs_at', now());
        }
        DB::exec('UPDATE stores SET tariffs_ok_at = ? WHERE id = ?', [now(), $storeId]);
        $this->setNext('tariffs_next_at', 24 * 3600);
        return count($rows);
    }

    // ------------------------------------------------------------------ 6. Остатки и объём (отчёт WB)

    /**
     * Отчёт «Остатки на складах WB» асинхронный: создаём задание → ждём готовности → скачиваем.
     * Из него берём объём товара (л) и остатки: на складах, в пути к покупателям, возвраты в пути.
     */
    private function syncRemains(): int
    {
        $state = json_decode((string) ($this->store['remains_state'] ?? ''), true) ?: [];
        $storeId = (int) $this->store['id'];
        $saveState = function (?array $st): void {
            DB::exec('UPDATE stores SET remains_state = ? WHERE id = ?', [$st ? json_encode($st) : null, $this->store['id']]);
            $this->store['remains_state'] = $st ? json_encode($st) : null;
        };
        if (empty($state['task'])) {
            $task = $this->wb->remainsCreate();
            $saveState(['task' => $task, 'at' => time()]);
            $this->setNext('remains_next_at', 60);
            return 0;
        }
        $status = $this->wb->remainsStatus((string) $state['task']);
        if (in_array($status, ['new', 'processing'], true) && time() - (int) $state['at'] < 1800) {
            $this->setNext('remains_next_at', 60);
            return 0;
        }
        if ($status !== 'done') {
            $saveState(null);
            $this->setNext('remains_next_at', 600);
            return 0;
        }
        $rows = $this->wb->remainsDownload((string) $state['task']);
        $count = 0;
        foreach ($rows as $row) {
            if (empty($row['nmId'])) {
                continue;
            }
            $toClient = $fromClient = $total = 0;
            $hasTotal = false;
            $sum = 0;
            foreach ((array) ($row['warehouses'] ?? []) as $w) {
                $name = mb_strtolower((string) ($w['warehouseName'] ?? ''));
                $q = (int) ($w['quantity'] ?? 0);
                if (str_contains($name, 'в пути до получател')) {
                    $toClient += $q;
                } elseif (str_contains($name, 'в пути возврат')) {
                    $fromClient += $q;
                } elseif (str_starts_with($name, 'всего')) {
                    $total = $q;
                    $hasTotal = true;
                } else {
                    $sum += $q;
                }
            }
            $volume = isset($row['volume']) && is_numeric($row['volume']) && (float) $row['volume'] > 0 ? round((float) $row['volume'], 3) : null;
            OrderRepository::rememberProduct($storeId, (int) $row['nmId'], $row['vendorCode'] ?? null, $row['brand'] ?? null, $row['subjectName'] ?? null);
            DB::exec('UPDATE products SET stock_wb = ?, stock_to_client = ?, stock_from_client = ?, stock_at = ?,
                    volume_l = COALESCE(?, volume_l), volume_src = IF(? IS NULL, volume_src, \'remains\')
                WHERE store_id = ? AND nm_id = ?', [$hasTotal ? $total : $sum, $toClient, $fromClient, now(), $volume, $volume, $storeId, (int) $row['nmId']]);
            $count++;
        }
        $saveState(null);
        DB::exec('UPDATE stores SET remains_ok_at = ? WHERE id = ?', [now(), $storeId]);
        $this->setNext('remains_next_at', 2 * 3600);
        return $count;
    }

    private function initialComplete(): bool
    {
        $state = json_decode((string) ($this->store['feed_state'] ?? ''), true) ?: [];
        if (!empty($this->scopes['analytics'])) {
            return !empty($state['done']);
        }
        return !empty($this->store['stats_cursor']);
    }
}
