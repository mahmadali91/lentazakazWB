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

    /** Создаёт платёж и возвращает ссылку на оплату. */
    public static function createPayment(array $user, string $planCode, int $months): string
    {
        $plan = Plans::get($planCode);
        $amount = Plans::price($planCode, $months);
        $paymentId = DB::insert('payments', [
            'user_id'    => $user['id'],
            'plan_code'  => $planCode,
            'months'     => $months,
            'amount'     => $amount,
            'provider'   => 'yookassa',
            'status'     => 'pending',
            'created_at' => now(),
        ]);
        $description = sprintf('Тариф «%s» на %d мес. — %s', $plan['name'], $months, config('app.name'));
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
                Plans::activate((int) $payment['user_id'], $payment['plan_code'], (int) $payment['months']);
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
