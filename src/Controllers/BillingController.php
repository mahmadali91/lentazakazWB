<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\Plans;
use App\Services\YooKassa;

final class BillingController
{
    public function index(): string
    {
        $user = Auth::requireOwner();
        return view('app/billing', [
            'title'    => 'Тариф и оплата',
            'user'     => $user,
            'plan'     => Plans::forUser($user),
            'plans'    => Plans::paid(),
            'payments' => DB::all('SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$user['id']]),
            'payEnabled' => YooKassa::enabled(),
            'nav'      => 'billing',
        ], 'app');
    }

    public function pay(): never
    {
        $user = Auth::requireOwner();
        $code = (string) input('plan', '');
        $months = (int) input('months', 1);
        if (!isset(Plans::paid()[$code]) || !isset(Plans::PERIODS[$months])) {
            flash('error', 'Выберите тариф и срок.');
            redirect('/app/billing');
        }
        // Магазинов в тарифе (для тарифов с докупкой): сверх включённых — доп. магазины
        $plan = Plans::get($code);
        $extra = max(0, min(Plans::maxExtra($plan), (int) input('stores', $plan['stores']) - (int) $plan['stores']));
        $this->checkout($user, $code, $months, $extra);
    }

    /** Докупка магазинов к действующему тарифу до конца подписки. */
    public function stores(): never
    {
        $user = Auth::requireOwner();
        $qty = (int) input('qty', 1);
        if (Plans::addonPrice($user, $qty) === null) {
            flash('error', 'Докупить столько магазинов нельзя: максимум — ' . (int) (Plans::get((string) $user['plan_code'])['stores_max'] ?? 0) . ' магазинов на тарифе.');
            redirect('/app/billing');
        }
        $this->checkout($user, (string) $user['plan_code'], 0, $qty);
    }

    private function checkout(array $user, string $code, int $months, int $extra): never
    {
        if (!YooKassa::enabled()) {
            flash('error', 'Онлайн-оплата пока не подключена. Свяжитесь с поддержкой: ' . trim((support_telegram() !== '' ? 'Telegram @' . support_telegram() : '') . ' ' . config('app.support_email')));
            redirect('/app/billing');
        }
        try {
            redirect(YooKassa::createPayment($user, $code, $months, $extra));
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/app/billing');
        }
    }

    public function return(): never
    {
        $user = Auth::requireOwner();
        $payment = DB::one('SELECT * FROM payments WHERE id = ? AND user_id = ?', [(int) input('payment', 0), $user['id']]);
        if ($payment && $payment['provider_id']) {
            try {
                $status = YooKassa::sync($payment['provider_id']);
                if ($status === 'succeeded') {
                    flash('success', ($payment['kind'] ?? 'plan') === 'stores' ? 'Оплата прошла успешно! Магазины добавлены — подключите их в разделе «Магазины».' : 'Оплата прошла успешно! Тариф активирован.');
                } elseif ($status === 'canceled') {
                    flash('error', 'Платёж отменён. Попробуйте ещё раз.');
                } else {
                    flash('success', 'Платёж обрабатывается. Тариф активируется автоматически после подтверждения.');
                }
            } catch (\RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('/app/billing');
    }
}
