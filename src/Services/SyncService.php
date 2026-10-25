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
        $column = ['feed' => 'feed_next_at', 'mp' => 'mp_next_at', 'stats' => 'stats_next_at', 'cards' => 'cards_next_at'][$source];
        try {
            $rows = (int) $fn();
            $this->log($source, true, $rows, null);
            if ($this->store['last_error']) {
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
            if ($e->isAuthError()) {
                // Токен отозван или у него нет категории — отключаем источник, пользователь увидит предупреждение
                $this->scopes[$this->scopeOf($source)] = false;
                DB::exec('UPDATE stores SET scopes = ? WHERE id = ?', [json_encode($this->scopes), $this->store['id']]);
            }
            if (!$e->isRateLimit()) {
                $msg = mb_substr($e->humanMessage(), 0, 480);
                DB::exec('UPDATE stores SET last_error = ?, last_error_at = ? WHERE id = ?', [$msg, now(), $this->store['id']]);
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
        return ['feed' => 'analytics', 'mp' => 'marketplace', 'stats' => 'statistics', 'cards' => 'content'][$source];
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
            $from = isset($state['high']) ? strtotime((string) $state['high']) - 15 * 60 : $this->historyStart();
            $start = date('c', max($minStart, min($from, time() - 60)));
            $offset = 0;
            $snapshot = null;
        }

        $data = $this->wb->orderFeed($start, $offset, self::FEED_LIMIT, $snapshot);
        $orders = $data['orders'] ?? [];
        $count = 0;
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($orders as $o) {
                if (empty($o['srid'])) {
                    continue;
                }
                OrderRepository::upsertFromFeed((int) $this->store['id'], $o, $this->silent);
                $count++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $snapshot = $snapshot ?? ($data['snapshotTime'] ?? date('c'));
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

    private function initialComplete(): bool
    {
        $state = json_decode((string) ($this->store['feed_state'] ?? ''), true) ?: [];
        if (!empty($this->scopes['analytics'])) {
            return !empty($state['done']);
        }
        return !empty($this->store['stats_cursor']);
    }
}
