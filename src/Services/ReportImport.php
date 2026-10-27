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
        @set_time_limit(300);
        // Кабинет WB отдаёт детализацию архивом .zip с .xlsx внутри — распаковываем
        $unzipped = self::unzipXlsx($path);
        if ($unzipped !== null) {
            try {
                return self::import($storeId, $unzipped, $name);
            } finally {
                @unlink($unzipped);
            }
        }
        // Детализация за неделю бывает на десятки тысяч строк — лист читается потоком, в базу пишется пачками
        $cols = self::columns($path);
        // Один проход по листу: строки складываем во временный буфер (в памяти до 16 МБ, дальше — на диске),
        // попутно собираем даты, чтобы определить неделю отчёта
        $buf = fopen('php://temp/maxmemory:' . 16 * 1024 * 1024, 'w+b');
        $dates = [];
        $count = 0;
        foreach (self::sheetRows($path, true) as $line) {
            if (!array_filter($line, static fn($v) => $v !== null && $v !== '')) {
                continue;
            }
            $r = self::mapRow($line, $cols);
            if (!empty($r['sale_dt'])) {
                $dates[] = substr($r['sale_dt'], 0, 10);
            }
            $ser = serialize($r);
            fwrite($buf, pack('N', strlen($ser)) . $ser);
            $count++;
        }
        if (!$count) {
            throw new \RuntimeException('в файле нет строк');
        }
        sort($dates);
        $mid = $dates[intdiv(count($dates), 2)] ?? date('Y-m-d');
        unset($dates);
        $from = Finance::weekStart($mid);
        $to = date('Y-m-d', strtotime($from) + 6 * 86400);
        // Полностью загруженный из WB отчёт за эту неделю уже в расчёте; пока детализация из WB не догрузилась — считаем по файлу
        if (DB::value('SELECT COUNT(*) FROM wb_reports WHERE store_id = ? AND date_from = ? AND report_id > 0 AND detail_done = 1', [$storeId, $from])) {
            throw new \RuntimeException('отчёт за неделю с ' . date_ru($from, false) . ' уже загружен из WB автоматически и учтён в расчётах — загружать файл не нужно');
        }
        $reportId = -((int) date('Ymd', strtotime($from)) * 100 + crc32(basename($name)) % 100);

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::exec('DELETE FROM wb_report_rows WHERE store_id = ? AND report_id = ?', [$storeId, $reportId]);
            DB::exec('DELETE FROM wb_reports WHERE store_id = ? AND report_id = ?', [$storeId, $reportId]);
            $all = ['store_id', 'rrd_id', 'report_id', 'date_from', 'date_to', ...array_values(array_unique($cols))];
            $sum = ['retail' => 0.0, 'pay' => 0.0, 'delivery' => 0.0, 'storage' => 0.0, 'acceptance' => 0.0, 'penalty' => 0.0, 'deduction' => 0.0, 'additional' => 0.0];
            $batch = [];
            $flush = static function () use (&$batch, $all, $pdo): void {
                if (!$batch) {
                    return;
                }
                $row = '(' . implode(',', array_fill(0, count($all), '?')) . ')';
                $pdo->prepare('INSERT INTO wb_report_rows (' . implode(',', $all) . ') VALUES ' . implode(',', array_fill(0, count($batch), $row)))
                    ->execute(array_merge(...$batch));
                $batch = [];
            };
            $i = 0;
            rewind($buf);
            while (strlen($len = (string) fread($buf, 4)) === 4) {
                $r = unserialize((string) fread($buf, unpack('N', $len)[1]), ['allowed_classes' => false]);
                $vals = [$storeId, $reportId * 100000 - $i - 1, $reportId, $from, $to];
                foreach (array_slice($all, 5) as $col) {
                    $vals[] = $r[$col] ?? (in_array($col, self::NUMERIC, true) ? 0 : null);
                }
                $batch[] = $vals;
                if (count($batch) >= 500) {
                    $flush();
                }
                $i++;
                $sign = ($r['doc_type'] ?? '') === 'Возврат' ? -1 : (($r['doc_type'] ?? '') === 'Продажа' ? 1 : 0);
                $sum['retail'] += $sign * ($r['retail_amount'] ?? 0);
                $sum['pay'] += $sign * ($r['for_pay'] ?? 0);
                foreach (['delivery' => 'delivery_service', 'storage' => 'paid_storage', 'acceptance' => 'paid_acceptance', 'penalty' => 'penalty', 'deduction' => 'deduction', 'additional' => 'additional'] as $k => $c) {
                    $sum[$k] += $r[$c] ?? 0;
                }
            }
            $flush();
            // Тот же отчёт, загруженный раньше под другим именем файла (например, архивом), — заменяем, чтобы суммы не задвоились
            $dups = DB::all('SELECT report_id FROM wb_reports WHERE store_id = ? AND report_id < 0 AND report_id <> ? AND date_from = ? AND rows_loaded = ? AND for_pay = ? AND retail_amount = ?',
                [$storeId, $reportId, $from, $i, round($sum['pay'], 2), round($sum['retail'], 2)]);
            foreach ($dups as $d) {
                DB::exec('DELETE FROM wb_report_rows WHERE store_id = ? AND report_id = ?', [$storeId, $d['report_id']]);
                DB::exec('DELETE FROM wb_reports WHERE store_id = ? AND report_id = ?', [$storeId, $d['report_id']]);
            }
            DB::insert('wb_reports', [
                'store_id' => $storeId, 'report_id' => $reportId, 'date_from' => $from, 'date_to' => $to, 'create_date' => date('Y-m-d'), 'report_type' => 1,
                'currency' => (string) (DB::value('SELECT currency FROM stores WHERE id = ?', [$storeId]) ?: 'RUB'), 'retail_amount' => round($sum['retail'], 2), 'for_pay' => round($sum['pay'], 2), 'delivery' => round($sum['delivery'], 2),
                'storage' => round($sum['storage'], 2), 'acceptance' => round($sum['acceptance'], 2), 'deduction' => round($sum['deduction'], 2),
                'penalty' => round($sum['penalty'], 2), 'additional' => round($sum['additional'], 2), 'cashback' => 0,
                'bank_payment' => round($sum['pay'] - $sum['delivery'] - $sum['storage'] - $sum['acceptance'] - $sum['penalty'] - $sum['deduction'] - $sum['additional'], 2),
                'rows_loaded' => $i, 'detail_done' => 1, 'loaded_at' => now(), 'updated_at' => now(),
            ]);
            DB::exec('INSERT IGNORE INTO products (store_id, nm_id, vendor_code, brand, subject, title, updated_at)
                SELECT store_id, nm_id, MAX(vendor_code), LEFT(MAX(brand), 120), LEFT(MAX(subject), 120), MAX(title), NOW() FROM wb_report_rows
                WHERE store_id = ? AND report_id = ? AND nm_id IS NOT NULL GROUP BY store_id, nm_id', [$storeId, $reportId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            fclose($buf);
        }
        return ['rows' => $i, 'from' => $from, 'to' => $to];
    }

    /** Если файл — .zip-архив с .xlsx внутри (а не сам .xlsx), распаковывает его во временный файл. */
    private static function unzipXlsx(string $path): ?string
    {
        if (!class_exists(\ZipArchive::class) || ($zip = new \ZipArchive())->open($path) !== true) {
            return null;
        }
        $inner = null;
        if ($zip->locateName('xl/workbook.xml') === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if ($st && preg_match('/\.xlsx$/i', $st['name']) && !str_starts_with(basename($st['name']), '~$')) {
                    if ($st['size'] > 300 * 1024 * 1024) {
                        $zip->close();
                        throw new \RuntimeException('файл слишком большой');
                    }
                    $inner = $st['name'];
                    break;
                }
            }
            if ($inner === null) {
                $zip->close();
                throw new \RuntimeException('в архиве нет файла .xlsx');
            }
        }
        if ($inner === null) {
            $zip->close();
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wbx');
        $ok = ($in = $zip->getStream($inner)) && ($out = fopen($tmp, 'wb')) && stream_copy_to_stream($in, $out) !== false;
        isset($out) && is_resource($out) && fclose($out);
        $zip->close();
        if (!$ok) {
            @unlink($tmp);
            throw new \RuntimeException('не удалось распаковать архив');
        }
        return $tmp;
    }

    /** Заголовок файла → [индекс столбца => колонка wb_report_rows]; проверка, что это детализация WB. */
    private static function columns(string $path): array
    {
        $header = null;
        foreach (self::sheetRows($path, false) as $line) {
            $header = $line;
            break;
        }
        if (!$header) {
            throw new \RuntimeException('в файле нет строк');
        }
        $header = array_map(static fn($h) => trim(str_replace("\u{A0}", ' ', (string) $h)), $header);
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
        return $cols;
    }

    /** Строка листа → значения колонок wb_report_rows. */
    private static function mapRow(array $line, array $cols): array
    {
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
        return $r;
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
    /**
     * Строки первого листа xlsx потоком (XMLReader): без загрузки всего листа в память.
     * @param bool $skipHeader пропустить первую строку (заголовок)
     */
    private static function sheetRows(string $path, bool $skipHeader): \Generator
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\XMLReader::class)) {
            throw new \RuntimeException('на сервере нет PHP-расширений zip и xmlreader');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('файл не похож на .xlsx');
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
        // Защита от «zip-бомбы»: лист с данными не больше 500 МБ в распакованном виде
        $stat = $zip->statName($sheetPath);
        if ($stat === false) {
            $zip->close();
            throw new \RuntimeException('в файле не найден лист с данными');
        }
        if ((int) $stat['size'] > 500 * 1024 * 1024) {
            $zip->close();
            throw new \RuntimeException('файл слишком большой');
        }
        $hasStrings = $zip->statName('xl/sharedStrings.xml') !== false;
        $zip->close();

        // Общие строки (текст ячеек) — тоже потоком
        static $cache = [];
        $key = $path . filemtime($path);
        if (!isset($cache[$key])) {
            $strings = [];
            if ($hasStrings) {
                $xr = new \XMLReader();
                $xr->open('zip://' . $path . '#xl/sharedStrings.xml', null, LIBXML_NONET);
                $text = null;
                while ($xr->read()) {
                    if ($xr->nodeType === \XMLReader::ELEMENT) {
                        if ($xr->localName === 'si') {
                            $text = '';
                            if ($xr->isEmptyElement) {
                                $strings[] = '';
                                $text = null;
                            }
                        } elseif ($xr->localName === 't' && $text !== null && !$xr->isEmptyElement && $xr->read()
                            && in_array($xr->nodeType, [\XMLReader::TEXT, \XMLReader::CDATA, \XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                            $text .= $xr->value;
                        } elseif ($xr->localName === 'rPh') {
                            $xr->next(); // фонетическая подсказка — не текст ячейки
                        }
                    } elseif ($xr->nodeType === \XMLReader::END_ELEMENT && $xr->localName === 'si') {
                        $strings[] = $text;
                        $text = null;
                    }
                }
                $xr->close();
            }
            $cache = [$key => $strings];
        }
        $strings = $cache[$key];

        $xr = new \XMLReader();
        if (!$xr->open('zip://' . $path . '#' . $sheetPath, null, LIBXML_NONET)) {
            throw new \RuntimeException('в файле не найден лист с данными');
        }
        static $letters = [];
        $first = true;
        $line = [];
        $col = 0;
        $type = '';
        $val = null;
        while ($xr->read()) {
            $node = $xr->nodeType;
            $name = $xr->localName;
            if ($node === \XMLReader::ELEMENT && $name === 'c') {
                $ref = (string) $xr->getAttribute('r');
                if ($ref !== '') {
                    $l = rtrim($ref, '0123456789');
                    if (!isset($letters[$l])) {
                        $n = 0;
                        foreach (str_split($l) as $ch) {
                            $n = $n * 26 + (ord($ch) - 64);
                        }
                        $letters[$l] = $n - 1;
                    }
                    $col = $letters[$l];
                }
                $type = (string) $xr->getAttribute('t');
                $val = null;
                if ($xr->isEmptyElement) {
                    $line[$col++] = null;
                }
            } elseif ($node === \XMLReader::ELEMENT && ($name === 'v' || $name === 't')) {
                if (!$xr->isEmptyElement && $xr->read() && in_array($xr->nodeType, [\XMLReader::TEXT, \XMLReader::CDATA, \XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    $val = ($val ?? '') . $xr->value;
                }
            } elseif ($node === \XMLReader::END_ELEMENT && $name === 'c') {
                $line[$col++] = match ($type) {
                    's'     => $strings[(int) $val] ?? '',
                    'b'     => $val === '1' ? '1' : '0',
                    default => $val,
                };
            } elseif ($name === 'row' && ($node === \XMLReader::END_ELEMENT || ($node === \XMLReader::ELEMENT && $xr->isEmptyElement))) {
                $out = $line;
                $line = [];
                $col = 0;
                if ($first) {
                    $first = false;
                    if ($skipHeader) {
                        continue;
                    }
                }
                yield $out;
            } elseif ($node === \XMLReader::ELEMENT && $name === 'row') {
                $line = [];
                $col = 0;
            }
        }
        $xr->close();
    }
}
