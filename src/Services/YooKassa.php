<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Приём оплаты тарифов через ЮKassa (API v3). */
final class YooKassa
{
    private const API = 'https://api.yookassa.ru/v3';

    public static function enabled(): bool
    {
        return (string) config('yookassa.shop_id', '') !== '' && (string) config('yookassa.secret_key', '') !== '';
    }

    private static function request(string $method, string $path, ?array $body = null, ?string $idempotenceKey = null): array
    {
        $ch = curl_init(rtrim((string) config('yookassa.api', self::API), '/') . $path);
        $headers = ['Content-Type: application/json'];
        if ($idempotenceKey) {
            $headers[] = 'Idempotence-Key: ' . $idempotenceKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => config('yookassa.shop_id') . ':' . config('yookassa.secret_key'),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status >= 400 || !is_array($data)) {
            log_message('payments', "YooKassa $method $path -> $status " . (is_string($raw) ? mb_substr($raw, 0, 500) : ''));
            throw new \RuntimeException('Платёжная система вернула ошибку. Попробуйте позже.');
        }
        return $data;
    }

    /** Проверка ключей магазина (GET /v3/me): данные аккаунта или исключение с причиной. */
    public static function me(): array
    {
        return self::request('GET', '/me');
    }

    /**
     * Создаёт платёж и возвращает ссылку на оплату.
     * $months > 0 — тариф на срок (с $extra доп. магазинами); $months = 0 — докупка $extra магазинов до конца подписки.
     */
    public static function createPayment(array $user, string $planCode, int $months, int $extra = 0): string
    {
        $plan = Plans::get($planCode);
        $addon = $months === 0;
        $amount = $addon ? Plans::addonPrice($user, $extra) : Plans::price($planCode, $months, $extra);
        if ($amount === null) {
            throw new \RuntimeException('Докупить магазины сейчас нельзя: проверьте тариф и лимит магазинов.');
        }
        $paymentId = DB::insert('payments', [
            'user_id'      => $user['id'],
            'plan_code'    => $planCode,
            'kind'         => $addon ? 'stores' : 'plan',
            'months'       => $months,
            'extra_stores' => $extra,
            'amount'       => $amount,
            'provider'     => 'yookassa',
            'status'       => 'pending',
            'created_at'   => now(),
        ]);
        $description = $addon
            ? sprintf('Доп. магазин ×%d к тарифу «%s» до %s — %s', $extra, $plan['name'], date('d.m.Y', strtotime((string) $user['plan_expires_at'])), config('app.name'))
            : sprintf('Тариф «%s»%s на %d мес. — %s', $plan['name'], $extra ? ' + ' . $extra . ' доп. маг.' : '', $months, config('app.name'));
        $value = number_format($amount, 2, '.', '');
        $body = [
            'amount'       => ['value' => $value, 'currency' => 'RUB'],
            'capture'      => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => url('/app/billing/return?payment=' . $paymentId)],
            'description'  => mb_substr($description, 0, 128),
            'metadata'     => ['payment_id' => (string) $paymentId, 'user_id' => (string) $user['id']],
        ];
        if (config('yookassa.send_receipt')) {
            $body['receipt'] = [
                'customer' => ['email' => $user['email']],
                'items'    => [[
                    'description'     => mb_substr($description, 0, 128),
                    'quantity'        => '1.00',
                    'amount'          => ['value' => $value, 'currency' => 'RUB'],
                    'vat_code'        => (int) config('yookassa.vat_code', 1),
                    'payment_mode'    => 'full_payment',
                    'payment_subject' => 'service',
                ]],
            ];
        }
        $resp = self::request('POST', '/payments', $body, 'lz-' . $paymentId . '-' . bin2hex(random_bytes(4)));
        DB::exec('UPDATE payments SET provider_id = ? WHERE id = ?', [$resp['id'] ?? null, $paymentId]);
        $urlPay = $resp['confirmation']['confirmation_url'] ?? null;
        if (!$urlPay) {
            throw new \RuntimeException('Не удалось получить ссылку на оплату');
        }
        return $urlPay;
    }

    /**
     * Сверяет платёж с ЮKassa (по ID платежа ЮKassa) и при успехе активирует тариф. Идемпотентно.
     * @return string статус платежа в нашей БД
     */
    public static function sync(string $providerId): string
    {
        $remote = self::request('GET', '/payments/' . rawurlencode($providerId));
        $payment = DB::one('SELECT * FROM payments WHERE provider_id = ?', [$providerId]);
        if (!$payment) {
            return 'unknown';
        }
        if ($payment['status'] === 'succeeded') {
            return 'succeeded';
        }
        $status = (string) ($remote['status'] ?? '');
        $paid = $status === 'succeeded' && !empty($remote['paid']);
        $sameAmount = isset($remote['amount']['value']) && abs((float) $remote['amount']['value'] - (float) $payment['amount']) < 0.01;
        if ($paid && $sameAmount) {
            $updated = DB::exec("UPDATE payments SET status = 'succeeded', paid_at = ? WHERE id = ? AND status <> 'succeeded'", [now(), $payment['id']]);
            if ($updated) {
                if (($payment['kind'] ?? 'plan') === 'stores') {
                    Plans::addStores((int) $payment['user_id'], (int) $payment['extra_stores']);
                } else {
                    Plans::activate((int) $payment['user_id'], $payment['plan_code'], (int) $payment['months'], (int) ($payment['extra_stores'] ?? 0));
                }
            }
            return 'succeeded';
        }
        if ($status === 'canceled') {
            DB::exec("UPDATE payments SET status = 'canceled' WHERE id = ?", [$payment['id']]);
            return 'canceled';
        }
        return 'pending';
    }
}
