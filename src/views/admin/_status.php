<?php
/** Бейдж состояния доступа клиента. Ожидает $u (строка users) и $paid (bool). */
$exp = $u['plan_expires_at'] ? strtotime($u['plan_expires_at']) : null;
$days = $exp ? (int) ceil(($exp - time()) / 86400) : null;
if (!empty($u['is_super'])) {
    echo '<span class="badge badge-accent">Супер-админ · всё бесплатно</span>';
} elseif ((int) $u['is_blocked']) {
    echo '<span class="badge badge-cancel">Заблокирован</span>';
} elseif (($u['role'] ?? '') === 'admin') {
    echo '<span class="badge badge-accent">Администратор</span>';
} elseif ($u['plan_code'] === 'free') {
    echo '<span class="badge">Бесплатный</span>';
} elseif ($exp === null || $exp <= time()) {
    echo '<span class="badge badge-cancel">Истёк' . ($exp ? ' ' . date('d.m.Y', $exp) : '') . '</span>';
} else {
    $cls = $days <= 3 ? 'badge-cancel' : ($days <= 7 ? 'badge-return' : ($paid ? 'badge-buyout' : 'badge-created'));
    echo '<span class="badge ' . $cls . '">' . ($paid ? '' : 'Пробный · ') . 'ещё ' . $days . ' ' . plural($days, 'день', 'дня', 'дней') . '</span>';
}
