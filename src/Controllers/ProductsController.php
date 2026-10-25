<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\Plans;
use App\Services\Profit;
use App\Services\StoreService;

/** Товары: себестоимость, комиссия и настройки расчёта прибыли. */
final class ProductsController
{
    /** @return array{0: array, 1: array, 2: int[]} пользователь-кабинет, тариф, id магазинов */
    private function context(): array
    {
        Auth::require();
        $account = Auth::account();
        $plan = Plans::forUser($account);
        return [$account, $plan, StoreService::activeIds((int) $account['id'], $plan)];
    }

    private function storeIn(array $ids, string $alias = 'p'): string
    {
        return $ids ? "$alias.store_id IN (" . implode(',', array_map('intval', $ids)) . ')' : '1 = 0';
    }

    public function index(): string
    {
        $user = Auth::require();
        [$account, $plan, $storeIds] = $this->context();
        $q = mb_substr((string) input('q', ''), 0, 100);
        $onlyEmpty = (string) input('empty', '') === '1';

        $where = [$this->storeIn($storeIds)];
        $params = [];
        if ($q !== '') {
            $where[] = '(p.vendor_code LIKE :q1 OR p.title LIKE :q2 OR p.brand LIKE :q3 OR p.subject LIKE :q4 OR p.nm_id = :qn)';
            $like = '%' . $q . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'qn' => ctype_digit($q) ? (int) $q : -1];
        }
        if ($onlyEmpty) {
            $where[] = 'p.cost_price IS NULL';
        }
        $since = date('Y-m-d H:i:s', strtotime('today') - 29 * 86400);
        $products = DB::all('SELECT p.*, s.name AS store_name,
                (SELECT COUNT(*) FROM orders o WHERE o.store_id = p.store_id AND o.nm_id = p.nm_id AND o.order_created_at >= :since) AS orders_30,
                (SELECT AVG(o.seller_price) FROM orders o WHERE o.store_id = p.store_id AND o.nm_id = p.nm_id AND o.order_created_at >= :since2) AS avg_price
            FROM products p JOIN stores s ON s.id = p.store_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY orders_30 DESC, p.nm_id DESC LIMIT 500', $params + ['since' => $since, 'since2' => $since]);

        $total = (int) DB::value('SELECT COUNT(*) FROM products p WHERE ' . $this->storeIn($storeIds));
        $withCost = (int) DB::value('SELECT COUNT(*) FROM products p WHERE p.cost_price IS NOT NULL AND ' . $this->storeIn($storeIds));

        return view('app/products', [
            'title'     => 'Товары и себестоимость',
            'user'      => $user,
            'plan'      => $plan,
            'products'  => $products,
            'settings'  => Profit::settings($account),
            'canEdit'   => !Auth::isMember() && !empty($plan['profit']),
            'q'         => $q,
            'onlyEmpty' => $onlyEmpty,
            'total'     => $total,
            'withCost'  => $withCost,
            'multi'     => count($storeIds) > 1,
            'nav'       => 'products',
        ], 'app');
    }

    private function requireEdit(): array
    {
        $owner = Auth::requireOwner();
        $plan = Plans::forUser($owner);
        if (empty($plan['profit'])) {
            flash('error', 'Себестоимость и расчёт прибыли доступны на платных тарифах.');
            redirect('/app/billing');
        }
        return [$owner, StoreService::activeIds((int) $owner['id'], $plan)];
    }

    private static function num(mixed $v): ?float
    {
        $v = str_replace([' ', ','], ['', '.'], trim((string) $v));
        return $v === '' || !is_numeric($v) ? null : max(0.0, round((float) $v, 2));
    }

    /** Сохранение себестоимости и комиссии из таблицы товаров. */
    public function save(): never
    {
        [, $storeIds] = $this->requireEdit();
        $costs = (array) ($_POST['cost'] ?? []);
        $comms = (array) ($_POST['comm'] ?? []);
        $updated = 0;
        foreach ($costs as $key => $cost) {
            if (!preg_match('/^(\d+)_(\d+)$/', (string) $key, $m) || !in_array((int) $m[1], $storeIds, true)) {
                continue;
            }
            $comm = self::num($comms[$key] ?? '');
            $updated += DB::exec(
                'UPDATE products SET cost_price = ?, commission_pct = ? WHERE store_id = ? AND nm_id = ?',
                [self::num($cost), $comm !== null ? min(100.0, $comm) : null, (int) $m[1], (int) $m[2]]
            );
        }
        flash('success', 'Сохранено. Изменено товаров: ' . $updated . '.');
        redirect('/app/products?' . http_build_query(['q' => input('q', ''), 'empty' => input('empty', '')]));
    }

    public function settings(): never
    {
        [$owner] = $this->requireEdit();
        Profit::save((int) $owner['id'], $_POST);
        flash('success', 'Параметры расчёта прибыли сохранены.');
        redirect('/app/products');
    }

    /** Шаблон/выгрузка себестоимости в CSV (Excel). */
    public function export(): never
    {
        [, , $storeIds] = $this->context();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sebestoimost_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Артикул WB', 'Артикул продавца', 'Товар', 'Магазин', 'Себестоимость, ₽', 'Комиссия WB, %'], ';');
        $rows = DB::all('SELECT p.*, s.name AS store_name FROM products p JOIN stores s ON s.id = p.store_id WHERE ' . $this->storeIn($storeIds) . ' ORDER BY p.vendor_code');
        foreach ($rows as $p) {
            fputcsv($out, [
                $p['nm_id'], $p['vendor_code'], $p['title'] ?: trim($p['subject'] . ' ' . $p['brand']), $p['store_name'],
                $p['cost_price'] !== null ? str_replace('.', ',', (string) (float) $p['cost_price']) : '',
                $p['commission_pct'] !== null ? str_replace('.', ',', (string) (float) $p['commission_pct']) : '',
            ], ';');
        }
        fclose($out);
        exit;
    }

    /**
     * Загрузка себестоимости из CSV: первая колонка — артикул WB или артикул продавца,
     * далее — себестоимость; необязательная колонка «Комиссия WB, %». Формат совпадает с выгрузкой.
     */
    public function import(): never
    {
        [, $storeIds] = $this->requireEdit();
        $file = $_FILES['file']['tmp_name'] ?? '';
        if (!$file || !is_uploaded_file($file) || ($_FILES['file']['size'] ?? 0) > 5 * 1024 * 1024) {
            flash('error', 'Выберите CSV-файл до 5 МБ.');
            redirect('/app/products');
        }
        $content = (string) file_get_contents($file);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $delimiter = substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $header = str_getcsv(array_shift($lines) ?? '', $delimiter);
        $costCol = 1;
        $commCol = null;
        foreach ($header as $i => $h) {
            $h = mb_strtolower((string) $h);
            if (str_contains($h, 'себест')) {
                $costCol = $i;
            }
            if (str_contains($h, 'комисс')) {
                $commCol = $i;
            }
        }
        $in = $this->storeIn($storeIds, 'products');
        $updated = 0;
        $missed = 0;
        foreach ($lines as $line) {
            $row = str_getcsv($line, $delimiter);
            $key = trim((string) ($row[0] ?? ''));
            $cost = self::num($row[$costCol] ?? '');
            if ($key === '' || $cost === null) {
                continue;
            }
            $comm = $commCol !== null ? self::num($row[$commCol] ?? '') : null;
            $set = $commCol !== null ? 'cost_price = ?, commission_pct = ?' : 'cost_price = ?';
            $vals = $commCol !== null ? [$cost, $comm !== null ? min(100.0, $comm) : null] : [$cost];
            $n = ctype_digit($key)
                ? DB::exec("UPDATE products SET $set WHERE $in AND (nm_id = ? OR vendor_code = ?)", [...$vals, (int) $key, $key])
                : DB::exec("UPDATE products SET $set WHERE $in AND vendor_code = ?", [...$vals, $key]);
            if ($n) {
                $updated += $n;
            } else {
                $missed++;
            }
        }
        flash('success', "Загружено: обновлено товаров — $updated" . ($missed ? ", не найдено строк — $missed" : '') . '.');
        redirect('/app/products');
    }
}
