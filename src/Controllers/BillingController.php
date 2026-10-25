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
        $user = Auth::require();
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
        $user = Auth::require();
        $code = (string) input('plan', '');
        $months = (int) input('months', 1);
        if (!isset(Plans::paid()[$code]) || !isset(Plans::PERIODS[$months])) {
            flash('error', 'Выберите тариф и срок.');
            redirect('/app/billing');
        }
        if (!YooKassa::enabled()) {
            flash('error', 'Онлайн-оплата пока не подключена. Напишите в поддержку: ' . config('app.support_email'));
            redirect('/app/billing');
        }
        try {
            redirect(YooKassa::createPayment($user, $code, $months));
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/app/billing');
        }
    }

    public function return(): never
    {
        $user = Auth::require();
        $payment = DB::one('SELECT * FROM payments WHERE id = ? AND user_id = ?', [(int) input('payment', 0), $user['id']]);
        if ($payment && $payment['provider_id']) {
            try {
                $status = YooKassa::sync($payment['provider_id']);
                if ($status === 'succeeded') {
                    flash('success', 'Оплата прошла успешно! Тариф активирован.');
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
