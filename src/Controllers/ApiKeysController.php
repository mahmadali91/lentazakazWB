<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\ApiTools;
use App\Services\Plans;

/** Страница «API и Claude»: ключи доступа к API и MCP-серверу (создаёт и отзывает владелец кабинета). */
final class ApiKeysController
{
    private const MAX_KEYS = 10;

    public function index(): string
    {
        $user = Auth::require();
        $account = Auth::account();
        $newKey = $_SESSION['new_api_key'] ?? null;
        unset($_SESSION['new_api_key']);
        return view('app/api', [
            'title'   => 'API и Claude',
            'user'    => $user,
            'plan'    => Plans::forUser($account),
            'keys'    => Auth::isMember() ? [] : DB::all('SELECT * FROM api_keys WHERE user_id = ? ORDER BY id DESC', [(int) $account['id']]),
            'newKey'  => $newKey,
            'tools'   => ApiTools::definitions($account),
            'baseUrl' => rtrim((string) config('app.url'), '/'),
            'isMember' => Auth::isMember(),
            'nav'     => 'api',
        ], 'app');
    }

    public function create(): never
    {
        $owner = Auth::requireOwner();
        if ((int) DB::value('SELECT COUNT(*) FROM api_keys WHERE user_id = ?', [(int) $owner['id']]) >= self::MAX_KEYS) {
            flash('error', 'Можно создать не больше ' . self::MAX_KEYS . ' ключей. Удалите ненужные.');
            redirect('/app/api');
        }
        $key = 'lz_' . bin2hex(random_bytes(20));
        $name = mb_substr(trim((string) input('name', '')), 0, 80) ?: 'Claude';
        DB::insert('api_keys', [
            'user_id' => (int) $owner['id'], 'name' => $name, 'token_hash' => hash('sha256', $key),
            'token_hint' => '…' . substr($key, -6), 'created_at' => now(),
        ]);
        $_SESSION['new_api_key'] = ['key' => $key, 'name' => $name];
        redirect('/app/api');
    }

    public function delete(string $id): never
    {
        $owner = Auth::requireOwner();
        DB::exec('DELETE FROM api_keys WHERE id = ? AND user_id = ?', [(int) $id, (int) $owner['id']]);
        flash('success', 'Ключ отозван — подключения с ним больше не работают.');
        redirect('/app/api');
    }
}
