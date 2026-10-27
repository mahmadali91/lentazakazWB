<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\AccountTransfer;
use App\Services\Max;
use App\Services\Plans;
use App\Services\Telegram;
use App\Services\YooKassa;

/** Доступ агентства к кабинетам клиентов (как WB Partners) и передача аккаунта сотруднику. */
final class AccountController
{
    /** Агентство: переключение между своим кабинетом и кабинетами клиентов. */
    public function switch(): never
    {
        Auth::require();
        $id = (int) input('account', 0);
        if (!Auth::switchAccount($id)) {
            flash('error', 'Доступ к этому кабинету закрыт или истёк.');
        }
        redirect('/app');
    }

    /** Владелец выдаёт агентству доступ к своему кабинету по ID агентства (необязательно — до даты). */
    public function grantAdd(): never
    {
        $owner = Auth::requireOwner();
        $agencyId = (int) ltrim(preg_replace('/\D/', '', (string) input('agency', '')), '0');
        $agency = $agencyId ? DB::one('SELECT * FROM users WHERE id = ? AND owner_id IS NULL AND is_blocked = 0', [$agencyId]) : null;
        if (!$agency || (int) $agency['id'] === (int) $owner['id']) {
            flash('error', 'Агентство с таким ID не найдено. ID — 6 цифр, его видно в профиле агентства.');
            redirect('/app/settings#agency');
        }
        if (!in_array(Plans::forUser($agency)['code'], ['business', 'super'], true)) {
            flash('error', 'У аккаунта ID ' . client_code($agencyId) . ' нет действующего тарифа «' . Plans::get('business')['name'] . '» — доступ можно выдать только агентству.');
            redirect('/app/settings#agency');
        }
        $until = (string) input('until', '');
        $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) && $until >= date('Y-m-d') ? $until . ' 23:59:59' : null;
        DB::exec('INSERT INTO account_grants (owner_id, agency_id, expires_at, created_at) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)', [$owner['id'], $agencyId, $expires, now()]);
        self::notify($agency, '🔑 Клиент <b>' . e($owner['name'] ?: $owner['email']) . '</b> (ID ' . client_code((int) $owner['id']) . ') открыл вашему агентству доступ к кабинету'
            . ($expires ? ' до ' . date('d.m.Y', strtotime($expires)) : '') . '. Переключиться можно в меню сайта «Кабинет».');
        flash('success', 'Агентство ID ' . client_code($agencyId) . ' получило доступ к вашему кабинету' . ($expires ? ' до ' . date_ru($expires, false) : '') . '. Оплата тарифа остаётся за вами.');
        redirect('/app/settings#agency');
    }

    public function grantDelete(string $agencyId): never
    {
        $owner = Auth::requireOwner();
        DB::exec('DELETE FROM account_grants WHERE owner_id = ? AND agency_id = ?', [$owner['id'], (int) $agencyId]);
        flash('success', 'Доступ агентства ID ' . client_code((int) $agencyId) . ' закрыт.');
        redirect('/app/settings#agency');
    }

    /** Передача аккаунта сотруднику: оплата месяца тарифа 2 (супер-админу — бесплатно), затем выделение кабинета. */
    public function transfer(string $memberId): never
    {
        $owner = Auth::requireOwner();
        try {
            ['member' => $member, 'stores' => $stores] = AccountTransfer::check((int) $owner['id'], (int) $memberId);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/app/settings#team');
        }
        $price = AccountTransfer::price($owner);
        if ($price <= 0) {
            AccountTransfer::execute((int) $owner['id'], (int) $memberId);
            self::notify($member, '✅ Вам передан кабинет с ' . count($stores) . ' ' . plural(count($stores), 'магазином', 'магазинами', 'магазинами')
                . ' на тарифе «' . Plans::get(AccountTransfer::PLAN)['name'] . '» на месяц. Войдите на сайт со своим логином.');
            flash('success', 'Аккаунт ' . $member['email'] . ' выделен в отдельный кабинет (ID ' . client_code((int) $member['id']) . ') на тарифе «'
                . Plans::get(AccountTransfer::PLAN)['name'] . '» до ' . date_ru(date('Y-m-d', strtotime('+1 month')), false) . '.');
            redirect('/app/settings#team');
        }
        if (!YooKassa::enabled()) {
            flash('error', 'Онлайн-оплата не подключена — напишите в поддержку, чтобы передать аккаунт.');
            redirect('/app/settings#team');
        }
        try {
            redirect(YooKassa::createPayment($owner, AccountTransfer::PLAN, 1, max(0, count($stores) - 1),
                ['amount' => $price, 'member_id' => (int) $member['id'], 'login' => (string) $member['email']]));
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/app/settings#team');
        }
    }

    /** Сообщение пользователю в MAX и Telegram (что подключено). */
    private static function notify(array $user, string $html): void
    {
        if (!empty($user['max_user_id']) && Max::enabled()) {
            Max::send($user['max_user_id'], $html);
        }
        if (!empty($user['tg_chat_id']) && Telegram::enabled()) {
            Telegram::send($user['tg_chat_id'], $html);
        }
    }
}
