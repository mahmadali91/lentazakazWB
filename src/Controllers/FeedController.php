<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\OrderRepository;
use App\Services\Plans;
use App\Services\Profit;
use App\Services\StoreService;

final class FeedController
{
    /**
     * Разбирает фильтры ленты из запроса с учётом ограничений тарифа.
     * @return array{0: int[], 1: array}
     */
    public static function filters(array $account, array $plan): array
    {
        $activeIds = StoreService::activeIds((int) $account['id'], $plan);
        $storeId = (int) input('store', 0);
        $storeIds = $storeId && in_array($storeId, $activeIds, true) ? [$storeId] : $activeIds;

        $period = (string) input('period', 'today');
        $minFrom = strtotime('today') - ((int) $plan['history_days'] - 1) * 86400;
        switch ($period) {
            case 'yesterday':
                $from = strtotime('yesterday');
                $to = strtotime('today') - 1;
                break;
            case '7d':
                $from = strtotime('today') - 6 * 86400;
                $to = time();
                break;
            case '30d':
                $from = strtotime('today') - 29 * 86400;
                $to = time();
                break;
            case 'custom':
                $from = strtotime((string) input('from', date('Y-m-d'))) ?: strtotime('today');
                $to = (strtotime((string) input('to', date('Y-m-d'))) ?: time()) + 86399;
                break;
            default:
                $period = 'today';
                $from = strtotime('today');
                $to = time();
        }
        $from = max($from, $minFrom);
        $f = [
            'period' => $period,
            'from'   => date('Y-m-d H:i:s', $from),
            'to'     => date('Y-m-d H:i:s', max($from, $to)),
            'status' => (string) input('status', ''),
            'scheme' => (string) input('scheme', ''),
            'q'      => mb_substr((string) input('q', ''), 0, 100),
            // Фильтр «Выкупы / Отмены / Возвраты» — по дате события, а не по дате заказа
            'date_by' => in_array((string) input('status', ''), ['buyout', 'cancel', 'return'], true) ? 'status' : '',
            'store'  => count($storeIds) === 1 && $storeId ? $storeId : 0,
        ];
        return [$storeIds, $f];
    }

    /** Настройки прибыли кабинета или null, если расчёт прибыли не входит в тариф. */
    public static function profitSettings(array $account, array $plan): ?array
    {
        return !empty($plan['profit']) ? Profit::settings($account) : null;
    }

    public function index(): string
    {
        $user = Auth::require();
        $account = Auth::account();
        $plan = Plans::forUser($account);
        $stores = StoreService::forUser((int) $account['id']);
        [$storeIds, $f] = self::filters($account, $plan);
        return view('app/feed', [
            'title'    => 'Лента заказов',
            'user'     => $user,
            'plan'     => $plan,
            'stores'   => $stores,
            'activeIds'=> StoreService::activeIds((int) $account['id'], $plan),
            'filters'  => $f,
            'cronLast' => DB::setting('cron_last_run'),
            'nav'      => 'feed',
        ], 'app');
    }

    /** Выгрузка в CSV (открывается в Excel). */
    public function export(): never
    {
        Auth::require();
        $account = Auth::account();
        $plan = Plans::forUser($account);
        if (empty($plan['export'])) {
            flash('error', 'Выгрузка в Excel доступна на тарифах «Про» и «Бизнес».');
            redirect('/app/billing');
        }
        [$storeIds, $f] = self::filters($account, $plan);
        $profit = self::profitSettings($account, $plan);
        $name = 'orders_' . date('Y-m-d_H-i') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Дата заказа', 'Статус', 'Дата статуса', 'Магазин', 'Схема', 'Артикул продавца', 'Артикул WB', 'Товар', 'Бренд', 'Предмет', 'Размер', 'Цена продавца, ₽', 'Цена для покупателя, ₽', 'СПП, %', 'Склад', 'Город доставки', 'Округ', 'ID заказа (srid)', 'Сборочное задание', 'B2B', 'Себестоимость, ₽', 'Прибыль (оценка), ₽'], ';');
        foreach (OrderRepository::all($storeIds, $f, 50000, $profit) as $o) {
            fputcsv($out, [
                date_ru($o['created_at']),
                $o['status_label'] . ($o['cancel_type'] ? " ({$o['cancel_type']})" : ''),
                date_ru($o['status_at']),
                $o['store'],
                $o['scheme_label'],
                $o['article'],
                $o['nm_id'],
                $o['title'],
                $o['brand'],
                $o['subject'],
                $o['size'],
                $o['price'] !== null ? str_replace('.', ',', (string) $o['price']) : '',
                $o['finished_price'] !== null ? str_replace('.', ',', (string) $o['finished_price']) : '',
                $o['spp'],
                $o['warehouse'],
                $o['city'],
                $o['district'],
                $o['srid'],
                $o['mp_order_id'],
                $o['is_b2b'] ? 'да' : '',
                $o['cost'] !== null ? str_replace('.', ',', (string) $o['cost']) : '',
                $o['profit'] !== null ? str_replace('.', ',', (string) $o['profit']) : '',
            ], ';');
        }
        fclose($out);
        exit;
    }
}
