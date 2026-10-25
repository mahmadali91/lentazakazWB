<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Хранение сессий в storage/sessions независимо от настроек PHP на хостинге
 * (на некоторых хостингах session.save_handler = memcached/redis, и смена save_path ломает сессии).
 */
final class FileSessionHandler implements \SessionHandlerInterface
{
    public function __construct(private readonly string $dir)
    {
    }

    private function file(string $id): string
    {
        return $this->dir . '/sess_' . preg_replace('/[^a-zA-Z0-9,-]/', '', $id);
    }

    public function open(string $path, string $name): bool
    {
        return is_dir($this->dir) || @mkdir($this->dir, 0700, true);
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $file = $this->file($id);
        return is_file($file) ? (string) @file_get_contents($file) : '';
    }

    public function write(string $id, string $data): bool
    {
        $ok = @file_put_contents($this->file($id), $data, LOCK_EX) !== false;
        if (!$ok) {
            log_message('error', 'Не удалось записать сессию в ' . $this->dir);
        }
        return $ok;
    }

    public function destroy(string $id): bool
    {
        $file = $this->file($id);
        if (is_file($file)) {
            @unlink($file);
        }
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $removed = 0;
        foreach (glob($this->dir . '/sess_*') ?: [] as $file) {
            if (filemtime($file) + $max_lifetime < time() && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
