<?php
declare(strict_types=1);

namespace App\Wb;

final class WbException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message, $status);
    }

    /** Токен недействителен или у него нет нужной категории. */
    public function isAuthError(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    public function isRateLimit(): bool
    {
        return $this->status === 429;
    }

    public function humanMessage(): string
    {
        return match (true) {
            $this->status === 401 => 'Токен WB недействителен или истёк. Создайте новый токен в кабинете продавца.',
            $this->status === 403 => 'У токена нет доступа к нужному разделу API WB. Проверьте категории токена.',
            $this->status === 429 => 'WB временно ограничил запросы (лимит). Повторим автоматически.',
            $this->status >= 500  => 'Сервис WB временно недоступен. Повторим автоматически.',
            $this->status === 0   => 'Нет связи с WB API: ' . $this->getMessage(),
            default               => 'Ошибка WB API (' . $this->status . '): ' . $this->getMessage(),
        };
    }
}
