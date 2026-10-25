<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Загрузка детализации отчёта реализации из файла .xlsx, скачанного в кабинете WB
 * («Финансовые отчёты» → отчёт → «Скачать детализацию»). Нужна, если токен без категории «Финансы».
 * Читаем xlsx без библиотек: это zip с XML (sharedStrings + первый лист).
 */
final class ReportImport
{
    /** Заголовок столбца в файле WB → колонка wb_report_rows. */
    private const MAP = [
        'Номер поставки' => 'gi_id', 'Предмет' => 'subject', 'Код номенклатуры' => 'nm_id', 'Бренд' => 'brand', 'Артикул поставщика' => 'vendor_code',
        'Название' => 'title', 'Размер' => 'ts_name', 'Баркод' => 'barcode', 'Тип документа' => 'doc_type', 'Обоснование для оплаты' => 'oper',
        'Дата заказа покупателем' => 'order_dt', 'Дата продажи' => 'sale_dt', 'Кол-во' => 'quantity', 'Цена розничная' => 'retail_price',
        'Вайлдберриз реализовал Товар (Пр)' => 'retail_amount', 'Итоговая согласованная скидка, %' => 'sale_percent',
        'Цена розничная с учетом согласованной скидки' => 'retail_price_disc', 'Размер кВВ, %' => 'commission_pct', 'Платформенные скидки, %' => 'spp', 'Скидка постоянного Покупателя (СПП), %' => 'spp',
        'Компенсация платёжных услуг/Комиссия за интеграцию платёжных сервисов' => 'acquiring_fee', 'Эквайринг/Комиссии за организацию платежей' => 'acquiring_fee',
        'К перечислению Продавцу за реализованный Товар' => 'for_pay', 'Количество доставок' => 'delivery_amount', 'Количество возврата' => 'return_amount',
        'Услуги по доставке товара покупателю' => 'delivery_service', 'Общая сумма штрафов' => 'penalty', 'Корректировка Вознаграждения Вайлдберриз (ВВ)' => 'additional',
        'Доплаты' => 'additional', 'Виды логистики, штрафов и корректировок ВВ' => 'bonus_type', 'Виды логистики, штрафов и доплат' => 'bonus_type',
        'Склад' => 'office_name', 'Наименование офиса доставки' => 'pvz_name', 'ШК' => 'shk_id', 'Srid' => 'srid', 'Хранение' => 'paid_storage',
        'Удержания' => 'deduction', 'Платная приемка' => 'paid_acceptance', 'Услуга по обработке Товара' => 'paid_acceptance',
        'Сумма баллов, удержанных по программе лояльности' => 'cashback_amount', 'Компенсация скидки по программе лояльности' => 'cashback_discount',
        'Стоимость участия в программе лояльности' => 'cashback_comm', 'Способы продажи и тип товара' => 'delivery_method',
        'Признак продажи юридическому лицу' => 'is_b2b',
    ];

    private const NUMERIC = ['quantity', 'retail_price', 'retail_amount', 'sale_percent', 'retail_price_disc', 'commission_pct', 'spp', 'acquiring_fee',
        'for_pay', 'delivery_amount', 'return_amount', 'delivery_service', 'penalty', 'additional', 'paid_storage', 'deduction', 'paid_acceptance',
        'cashback_amount', 'cashback_discount', 'cashback_comm'];

    /** @return array{rows: int, from: string, to: string} */
    public static function import(int $storeId, string $path, string $name): array
    {
        $sheet = self::readXlsx($path);
        if (count($sheet) < 2) {
            throw new \RuntimeException('в файле нет строк');
        }
        $header = array_map(static fn($h) => trim(str_replace("\u{A0}", ' ', (string) $h)), array_shift($sheet));
        $cols = [];
        foreach ($header as $i => $h) {
            if (isset(self::MAP[$h]) && !in_array(self::MAP[$h], $cols, true)) {
                $cols[$i] = self::MAP[$h];
            }
        }
        foreach (['doc_type', 'oper', 'for_pay', 'retail_price_disc'] as $need) {
            if (!in_array($need, $cols, true)) {
                throw new \RuntimeException('это не детализация отчёта реализации WB (нет столбца «' . array_search($need, self::MAP, true) . '»)');
            }
        }

        $rows = [];
        $dates = [];
        foreach ($sheet as $line) {
            if (!array_filter($line, static fn($v) => $v !== null && $v !== '')) {
                continue;
            }
            $r = [];
            foreach ($cols as $i => $col) {
                $v = $line[$i] ?? null;
                if (in_array($col, self::NUMERIC, true)) {
                    $v = is_numeric($v = str_replace([' ', ','], ['', '.'], (string) $v)) ? (float) $v : 0.0;
                } elseif (in_array($col, ['order_dt', 'sale_dt'], true)) {
                    $v = self::date($v);
                } elseif ($col === 'is_b2b') {
                    $v = in_array(mb_strtolower(trim((string) $v)), ['1', 'да', 'true'], true) ? 1 : 0;
                } elseif (in_array($col, ['gi_id', 'nm_id', 'shk_id'], true)) {
                    $v = ctype_digit(trim((string) $v)) ? (int) $v : null;
                } else {
                    $v = $v === null || $v === '' ? null : mb_substr(trim((string) $v), 0, $col === 'title' || $col === 'bonus_type' || $col === 'pvz_name' ? 255 : 120);
                }
                $r[$col] = $v;
            }
            if (!empty($r['sale_dt'])) {
                $dates[] = substr($r['sale_dt'], 0, 10);
            }
            $rows[] = $r;
        }
        if (!$rows) {
            throw new \RuntimeException('в файле нет строк');
        }
        sort($dates);
        $mid = $dates[intdiv(count($dates), 2)] ?? date('Y-m-d');
        $from = Finance::weekStart($mid);
        $to = date('Y-m-d', strtotime($from) + 6 * 86400);
        if (DB::value('SELECT COUNT(*) FROM wb_reports WHERE store_id = ? AND date_from = ? AND report_id > 0', [$storeId, $from])) {
            throw new \RuntimeException('отчёт за неделю ' . date_ru($from, false) . ' уже загружен из WB по API');
        }
        $reportId = -((int) date('Ymd', strtotime($from)) * 100 + crc32(basename($name)) % 100);

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::exec('DELETE FROM wb_report_rows WHERE store_id = ? AND report_id = ?', [$storeId, $reportId]);
            DB::exec('DELETE FROM wb_reports WHERE store_id = ? AND report_id = ?', [$storeId, $reportId]);
            $all = ['store_id', 'rrd_id', 'report_id', 'date_from', 'date_to', ...array_values(array_unique($cols))];
            $stmt = $pdo->prepare('INSERT INTO wb_report_rows (' . implode(',', $all) . ') VALUES (' . implode(',', array_fill(0, count($all), '?')) . ')');
            $sum = ['retail' => 0.0, 'pay' => 0.0, 'delivery' => 0.0, 'storage' => 0.0, 'acceptance' => 0.0, 'penalty' => 0.0, 'deduction' => 0.0, 'additional' => 0.0];
            foreach ($rows as $i => $r) {
                $vals = [$storeId, $reportId * 100000 - $i - 1, $reportId, $from, $to];
                foreach (array_slice($all, 5) as $col) {
                    $vals[] = $r[$col] ?? (in_array($col, self::NUMERIC, true) ? 0 : null);
                }
                $stmt->execute($vals);
                $sign = ($r['doc_type'] ?? '') === 'Возврат' ? -1 : (($r['doc_type'] ?? '') === 'Продажа' ? 1 : 0);
                $sum['retail'] += $sign * ($r['retail_amount'] ?? 0);
                $sum['pay'] += $sign * ($r['for_pay'] ?? 0);
                foreach (['delivery' => 'delivery_service', 'storage' => 'paid_storage', 'acceptance' => 'paid_acceptance', 'penalty' => 'penalty', 'deduction' => 'deduction', 'additional' => 'additional'] as $k => $c) {
                    $sum[$k] += $r[$c] ?? 0;
                }
            }
            DB::insert('wb_reports', [
                'store_id' => $storeId, 'report_id' => $reportId, 'date_from' => $from, 'date_to' => $to, 'create_date' => date('Y-m-d'), 'report_type' => 1,
                'currency' => 'RUB', 'retail_amount' => round($sum['retail'], 2), 'for_pay' => round($sum['pay'], 2), 'delivery' => round($sum['delivery'], 2),
                'storage' => round($sum['storage'], 2), 'acceptance' => round($sum['acceptance'], 2), 'deduction' => round($sum['deduction'], 2),
                'penalty' => round($sum['penalty'], 2), 'additional' => round($sum['additional'], 2), 'cashback' => 0,
                'bank_payment' => round($sum['pay'] - $sum['delivery'] - $sum['storage'] - $sum['acceptance'] - $sum['penalty'] - $sum['deduction'] - $sum['additional'], 2),
                'rows_loaded' => count($rows), 'detail_done' => 1, 'loaded_at' => now(), 'updated_at' => now(),
            ]);
            DB::exec('INSERT IGNORE INTO products (store_id, nm_id, vendor_code, brand, subject, title, updated_at)
                SELECT store_id, nm_id, MAX(vendor_code), LEFT(MAX(brand), 120), LEFT(MAX(subject), 120), MAX(title), NOW() FROM wb_report_rows
                WHERE store_id = ? AND report_id = ? AND nm_id IS NOT NULL GROUP BY store_id, nm_id', [$storeId, $reportId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['rows' => count($rows), 'from' => $from, 'to' => $to];
    }

    private static function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            // Дата Excel: дни с 30.12.1899
            return gmdate('Y-m-d H:i:s', (int) round(((float) $v - 25569) * 86400));
        }
        $t = strtotime(str_replace('.', '-', (string) $v));
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', (string) $v, $m)) {
            $t = strtotime("$m[3]-$m[2]-$m[1]");
        }
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /** Первый лист xlsx как массив строк (индексы столбцов с 0). */
    private static function readXlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('на сервере нет PHP-расширения zip');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('файл не похож на .xlsx');
        }
        $strings = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            foreach ($sx->si as $si) {
                $text = isset($si->t) ? (string) $si->t : '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
            }
        }
        $sheetPath = 'xl/worksheets/sheet1.xml';
        if (($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false && ($wb = $zip->getFromName('xl/workbook.xml')) !== false) {
            $wbx = simplexml_load_string($wb, \SimpleXMLElement::class, LIBXML_NONET);
            $rid = (string) ($wbx->sheets->sheet[0]->attributes('r', true)['id'] ?? '');
            foreach (simplexml_load_string($rels, \SimpleXMLElement::class, LIBXML_NONET)->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
        // Защита от «zip-бомбы»: лист с данными не больше 300 МБ в распакованном виде
        $stat = $zip->statName($sheetPath);
        if ($stat !== false && (int) $stat['size'] > 300 * 1024 * 1024) {
            $zip->close();
            throw new \RuntimeException('файл слишком большой');
        }
        $xml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException('в файле не найден лист с данными');
        }
        $out = [];
        $sx = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        foreach ($sx->sheetData->row as $row) {
            $line = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                $col = 0;
                foreach (str_split($m[1] ?? 'A') as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $type = (string) $c['t'];
                $v = match ($type) {
                    's'         => $strings[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    default     => isset($c->v) ? (string) $c->v : null,
                };
                $line[$col - 1] = $v;
            }
            $out[] = $line;
        }
        return $out;
    }
}
