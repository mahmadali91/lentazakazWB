<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Mailer;

/**
 * Доступ клиента к отдельному магазину кабинета (агентства) по email.
 * Новый email — создаётся аккаунт, на почту приходит ссылка активации (задать пароль), доступ открыт сразу.
 * Существующий аккаунт — на почту приходит ссылка подтверждения, доступ открывается после неё.
 */
final class StoreClients
{
    public const TTL_DAYS = 7;

    /** @return string сообщение для владельца кабинета */
    public static function invite(array $owner, array $store, string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Укажите email клиента — на него придёт письмо со ссылкой.');
        }
        if ($email === mb_strtolower((string) $owner['email'])) {
            throw new \InvalidArgumentException('Это ваш собственный email.');
        }
        $user = DB::one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user && !empty($user['owner_id'])) {
            throw new \InvalidArgumentException('Логин ' . $email . ' (ID ' . client_code((int) $user['id']) . ') — это сотрудник кабинета. Укажите другой email клиента.');
        }
        if ($user && (int) $user['is_blocked']) {
            throw new \InvalidArgumentException('Аккаунт ' . $email . ' (ID ' . client_code((int) $user['id']) . ') заблокирован.');
        }
        $agency = Contacts::of($owner)['name'] ?? ($owner['name'] ?: $owner['email']);
        if (!$user) {
            // Новый клиент: аккаунт без пароля, доступ к магазину открыт сразу — пароль он задаёт по ссылке из письма
            $userId = DB::insert('users', [
                'email' => $email, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'name' => '', 'invited_by' => (int) $owner['id'], 'plan_code' => 'free', 'created_at' => now(),
            ]);
            DB::insert('store_clients', ['store_id' => (int) $store['id'], 'owner_id' => (int) $owner['id'], 'user_id' => $userId,
                'created_at' => now(), 'confirmed_at' => now()]);
            $token = self::resetToken($userId);
            $sent = Mailer::send($email, 'Доступ к магазину «' . $store['name'] . '» — ' . config('app.name'),
                "Здравствуйте!\n\n$agency открывает вам доступ к магазину «{$store['name']}» в сервисе «" . config('app.name') . "»: лента заказов, товары и финансы.\n\n"
                . "Чтобы активировать кабинет, перейдите по ссылке и придумайте пароль (ссылка действует " . self::TTL_DAYS . " дней):\n" . url('/reset?token=' . $token . '&welcome=1')
                . "\n\nЛогин для входа — ваш email: $email\nЕсли ссылка устарела, восстановите пароль: " . url('/forgot') . "\n");
            return 'Создан аккаунт ' . $email . ' (ID ' . client_code((int) $userId) . '). ' . ($sent
                ? 'Мы отправили клиенту письмо — по ссылке он задаст пароль и сразу увидит магазин.'
                : 'Письмо не отправилось — передайте клиенту ссылку активации сами (действует ' . self::TTL_DAYS . ' дней): ' . url('/reset?token=' . $token . '&welcome=1'));
        }
        $row = DB::one('SELECT * FROM store_clients WHERE store_id = ? AND user_id = ?', [$store['id'], $user['id']]);
        if ($row && $row['confirmed_at']) {
            throw new \InvalidArgumentException('У ' . $email . ' (ID ' . client_code((int) $user['id']) . ') уже есть доступ к этому магазину.');
        }
        $token = bin2hex(random_bytes(32));
        if ($row) {
            DB::exec('UPDATE store_clients SET token_hash = ?, created_at = NOW() WHERE id = ?', [hash('sha256', $token), $row['id']]);
        } else {
            DB::insert('store_clients', ['store_id' => (int) $store['id'], 'owner_id' => (int) $owner['id'], 'user_id' => (int) $user['id'],
                'token_hash' => hash('sha256', $token), 'created_at' => now()]);
        }
        $sent = Mailer::send($email, 'Подтвердите доступ к магазину «' . $store['name'] . '» — ' . config('app.name'),
            "Здравствуйте!\n\n$agency открывает вашему аккаунту $email доступ к магазину «{$store['name']}» в сервисе «" . config('app.name') . "».\n\n"
            . "Чтобы принять доступ, перейдите по ссылке (действует " . self::TTL_DAYS . " дней):\n" . url('/access/confirm?token=' . $token)
            . "\n\nИли войдите в свой аккаунт ($email) — там будет кнопка «Принять».\nПосле подтверждения магазин появится в переключателе «Кабинет WB».\nЕсли вы не ждали это письмо — просто проигнорируйте его.\n");
        if (!$sent) {
            throw new \InvalidArgumentException('Аккаунт ' . $email . ' уже есть — ID ' . client_code((int) $user['id']) . ', но письмо для подтверждения не отправилось. Попробуйте ещё раз позже («отправить ещё раз»).');
        }
        return 'Аккаунт ' . $email . ' уже есть — ID ' . client_code((int) $user['id']) . '. Мы отправили ему письмо: доступ откроется после подтверждения по ссылке или кнопкой «Принять» в его аккаунте.';
    }

    /** Ссылка активации для нового клиента: токен восстановления пароля на 7 дней. */
    public static function resetToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        DB::exec('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
        DB::insert('password_resets', ['token_hash' => hash('sha256', $token), 'user_id' => $userId,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400)]);
        return $token;
    }

    /** Подтверждение по ссылке из письма. @return array|null строка доступа */
    public static function confirm(string $token): ?array
    {
        $row = DB::one('SELECT * FROM store_clients WHERE token_hash = ? AND confirmed_at IS NULL AND created_at > NOW() - INTERVAL ' . self::TTL_DAYS . ' DAY',
            [hash('sha256', $token)]);
        if (!$row) {
            return null;
        }
        DB::exec('UPDATE store_clients SET confirmed_at = NOW(), token_hash = NULL WHERE id = ?', [$row['id']]);
        return $row;
    }

    /** Приглашения, ждущие подтверждения, — показываем в аккаунте клиента (если письмо не дошло). */
    public static function pendingFor(int $userId): array
    {
        return DB::all('SELECT sc.id, s.name store_name, u.name owner_name, u.email owner_email, u.contacts FROM store_clients sc
            JOIN stores s ON s.id = sc.store_id JOIN users u ON u.id = sc.owner_id
            WHERE sc.user_id = ? AND sc.confirmed_at IS NULL AND sc.created_at > NOW() - INTERVAL ' . self::TTL_DAYS . ' DAY ORDER BY sc.id', [$userId]);
    }

    /** Клиенты магазинов кабинета: store_id => [строки]. */
    public static function forOwner(int $ownerId): array
    {
        $by = [];
        foreach (DB::all('SELECT sc.*, u.email, u.name, u.last_login_at FROM store_clients sc JOIN users u ON u.id = sc.user_id
            WHERE sc.owner_id = ? ORDER BY sc.id', [$ownerId]) as $r) {
            $by[(int) $r['store_id']][] = $r;
        }
        return $by;
    }

    /**
     * Клиент без своих магазинов после входа сразу попадает в кабинет агентства с его магазином.
     */
    public static function enterDefault(array $user): void
    {
        if (!empty($user['owner_id']) || Auth::acting() !== null
            || DB::value('SELECT id FROM stores WHERE user_id = ? LIMIT 1', [$user['id']])) {
            return;
        }
        foreach (Auth::grants($user) as $g) {
            if ($g['kind'] === 'client') {
                Auth::switchAccount((int) $g['owner_id']);
                return;
            }
        }
    }
}
