<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\ApiTools;

/**
 * Внешнее API по ключу (только чтение):
 *  - REST: GET|POST /api/v1/<инструмент>, ключ в заголовке Authorization: Bearer <ключ> (или X-Api-Key, или ?key=)
 *  - MCP:  POST /mcp/<ключ> — сервер Model Context Protocol (Streamable HTTP, JSON-ответы) для коннектора Claude.
 * Ключи создаёт владелец кабинета на странице «API и Claude»; в БД хранится только SHA-256 ключа.
 */
final class ApiV1Controller
{
    private const PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** Пользователь по ключу или null. */
    public static function userByKey(string $key): ?array
    {
        $key = trim($key);
        if (!preg_match('/^lz_[a-f0-9]{40}$/', $key)) {
            return null;
        }
        $row = DB::one('SELECT k.id key_id, u.* FROM api_keys k JOIN users u ON u.id = k.user_id WHERE k.token_hash = ?', [hash('sha256', $key)]);
        if (!$row || (int) $row['is_blocked'] || $row['owner_id'] !== null) {
            return null;
        }
        DB::exec('UPDATE api_keys SET requests = requests + 1, last_used_at = NOW(), last_ip = ? WHERE id = ?', [client_ip(), $row['key_id']]);
        unset($row['password_hash'], $row['tg_link_code']);
        return $row;
    }

    private static function keyFromRequest(): string
    {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)/i', $auth, $m)) {
            return $m[1];
        }
        return (string) ($_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '');
    }

    private static function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key, Mcp-Session-Id, Mcp-Protocol-Version');
        header('Access-Control-Expose-Headers: Mcp-Session-Id');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    private static function body(): array
    {
        $raw = (string) file_get_contents('php://input');
        $data = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    // ------------------------------------------------------------------ REST

    public function index(): never
    {
        self::cors();
        $user = self::userByKey(self::keyFromRequest());
        json_response([
            'name' => 'Лента заказов WB — API',
            'auth' => $user ? 'ok' : 'Передайте ключ: Authorization: Bearer lz_… (создаётся в кабинете: «API и Claude»)',
            'mcp' => rtrim((string) config('app.url'), '/') . '/mcp/<ключ>',
            'tools' => $user ? array_map(static fn($t) => ['name' => $t['name'], 'url' => '/api/v1/' . $t['name'], 'description' => $t['description'],
                'params' => $t['inputSchema']['properties']], ApiTools::definitions($user)) : [],
        ], $user ? 200 : 401);
    }

    public function tool(string $name): never
    {
        self::cors();
        $user = self::userByKey(self::keyFromRequest());
        if (!$user) {
            usleep(300000);
            json_response(['ok' => false, 'error' => 'Неверный или отозванный ключ API'], 401);
        }
        $args = $_SERVER['REQUEST_METHOD'] === 'POST' ? self::body() : $_GET;
        unset($args['key']);
        @set_time_limit(90);
        try {
            json_response(['ok' => true, 'tool' => $name, 'data' => ApiTools::call($name, $args, $user)]);
        } catch (\InvalidArgumentException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            log_message('api', $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            json_response(['ok' => false, 'error' => 'Внутренняя ошибка сервиса'], 500);
        }
    }

    // ------------------------------------------------------------------ MCP

    public function mcp(string $key): never
    {
        self::cors();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            // Поток событий (SSE) не нужен: на каждый запрос отвечаем JSON
            header('Allow: POST, OPTIONS');
            json_response(['error' => 'Используйте POST (MCP Streamable HTTP)'], 405);
        }
        $user = self::userByKey($key);
        if (!$user) {
            usleep(300000);
            json_response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Неверный или отозванный ключ API. Создайте новый в кабинете: «API и Claude».']], 404);
        }
        @set_time_limit(90);
        $payload = self::body();
        $batch = array_is_list($payload) && $payload !== [];
        $responses = [];
        foreach ($batch ? $payload : [$payload] as $msg) {
            $r = is_array($msg) ? $this->handle($msg, $user) : self::rpcError(null, -32600, 'Invalid Request');
            if ($r !== null) {
                $responses[] = $r;
            }
        }
        if (!$responses) {
            http_response_code(202);
            exit;
        }
        json_response($batch ? $responses : $responses[0]);
    }

    private function handle(array $msg, array $user): ?array
    {
        $id = $msg['id'] ?? null;
        $method = (string) ($msg['method'] ?? '');
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        if (!array_key_exists('id', $msg)) {
            return null; // уведомление (notifications/initialized и т. п.) — ответ не нужен
        }
        switch ($method) {
            case 'initialize':
                $asked = (string) ($params['protocolVersion'] ?? '');
                return self::rpcResult($id, [
                    'protocolVersion' => in_array($asked, self::PROTOCOLS, true) ? $asked : self::PROTOCOLS[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'lenta-zakazov-wb', 'title' => 'Лента заказов WB', 'version' => APP_VERSION],
                    'instructions' => 'Данные кабинета продавца Wildberries «' . $user['email'] . '» в сервисе «Лента заказов WB» (только чтение). '
                        . 'Начните с account_info (магазины и тариф) и sync_status (работает ли загрузка из WB). '
                        . 'Заказы — orders_summary и list_orders; чистая прибыль по отчётам реализации WB — finance_summary, finance_opiu, finance_sku, finance_reports, finance_order. '
                        . 'Суммы в рублях, даты — ' . date_default_timezone_get() . '.',
                ]);
            case 'ping':
                return self::rpcResult($id, new \stdClass());
            case 'tools/list':
                return self::rpcResult($id, ['tools' => ApiTools::definitions($user)]);
            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                try {
                    $data = ApiTools::call($name, $args, $user);
                    return self::rpcResult($id, [
                        'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                        'structuredContent' => $data,
                        'isError' => false,
                    ]);
                } catch (\InvalidArgumentException $e) {
                    return self::rpcResult($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
                } catch (\Throwable $e) {
                    log_message('api', 'mcp ' . $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                    return self::rpcResult($id, ['content' => [['type' => 'text', 'text' => 'Внутренняя ошибка сервиса при выполнении ' . $name]], 'isError' => true]);
                }
            case 'resources/list':
                return self::rpcResult($id, ['resources' => []]);
            case 'resources/templates/list':
                return self::rpcResult($id, ['resourceTemplates' => []]);
            case 'prompts/list':
                return self::rpcResult($id, ['prompts' => []]);
            default:
                return self::rpcError($id, -32601, 'Method not found: ' . $method);
        }
    }

    private static function rpcResult(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function rpcError(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
