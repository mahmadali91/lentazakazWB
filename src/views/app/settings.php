<?php
use App\Controllers\SettingsController;
use App\Services\Telegram;

$events = array_filter(explode(',', (string) $user['tg_events']));
$tgStores = Telegram::recipientStores($user);
// Выбор магазинов: «все» или отмеченные (для формы сотрудника и уведомлений)
$accessFields = static function (?array $access, array $stores, string $uid): string {
    $all = $access === null;
    $h = '<div class="access-pick" data-access>'
        . '<label class="check"><input type="radio" name="access" value="all" ' . ($all ? 'checked' : '') . '> все магазины кабинета</label>'
        . '<label class="check"><input type="radio" name="access" value="some" ' . ($all ? '' : 'checked') . '> только выбранные:</label>'
        . '<div class="access-list' . ($all ? ' hidden' : '') . '">';
    foreach ($stores as $s) {
        $h .= '<label class="check"><input type="checkbox" name="stores[]" value="' . (int) $s['id'] . '" ' . (!$all && in_array((int) $s['id'], $access, true) ? 'checked' : '') . '> ' . e($s['name']) . '</label>';
    }
    return $h . '</div></div>';
};
// Уровень доступа сотрудника / клиента
$roles = ['viewer' => 'Только просмотр', 'client' => 'Ведёт учёт — себестоимость, налог и расходы'];
$roleField = static function (string $role) use ($roles): string {
    $h = '<div class="field" style="margin:10px 0 0"><label>Уровень доступа</label><select name="member_role">';
    foreach ($roles as $k => $label) {
        $h .= '<option value="' . $k . '"' . ($k === $role ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h . '</select></div>';
};
$contacts = App\Services\Contacts::of($user);
?>
<div class="topbar"><h1>Настройки</h1></div>

<div class="stack page-narrow">
  <div class="card" id="notify">
    <div class="card-title"><?= icon('send', 18) ?> Уведомления о заказах — MAX и Telegram</div>
    <?php if (!$tgEnabled && !$maxEnabled): ?>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <p class="muted">Боты ещё не подключены. Вставьте токены ботов MAX и Telegram в <a href="/admin/settings">Админ-панель → Настройки сервиса</a> — после этого здесь появятся кнопки подключения.</p>
      <?php else: ?>
        <p class="muted">Уведомления скоро заработают — администратор подключает ботов.</p>
      <?php endif; ?>
    <?php elseif (empty($plan['telegram'])): ?>
      <p class="muted">Уведомления о заказах в MAX и Telegram доступны на тарифах <?= e(App\Services\Plans::namesWith('telegram')) ?>.</p>
      <a class="btn" href="/app/billing">Выбрать тариф</a>
    <?php else: ?>
      <p class="muted small">Бот присылает сообщение о каждом новом заказе: товар, цена, склад, город. Можно включить выкупы, отмены и возвраты. Подключите один или оба мессенджера.</p>

      <?php if ($maxEnabled): ?>
        <div class="notify-ch">
          <div class="notify-ch-head"><b>MAX</b> <span class="badge badge-buyout">рекомендуем</span></div>
          <?php if ($user['max_user_id']): ?>
            <div class="alert alert-success">MAX подключён — уведомления приходят в чат с ботом <?= e(config('max.bot_username')) ?>.</div>
            <div class="row">
              <form method="post" action="/app/settings/max/test"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Отправить тест</button></form>
              <form method="post" action="/app/settings/max/unlink"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Отключить</button></form>
            </div>
          <?php else: ?>
            <ol class="tg-steps">
              <li>Нажмите <b>«Подключить MAX»</b> — откроется бот в приложении или веб-версии MAX (<a href="https://web.max.ru" target="_blank" rel="noopener">web.max.ru</a>).</li>
              <li>В чате с ботом нажмите <span class="kbd">Начать</span>.</li>
              <li>Подождите до минуты и нажмите «Проверить подключение».</li>
            </ol>
            <div class="row">
              <a class="btn" href="<?= e(App\Services\Max::linkUrl((string) $user['max_link_code'])) ?>" target="_blank" rel="noopener"><?= icon('send', 16) ?> Подключить MAX</a>
              <a class="btn btn-ghost" href="/app/settings#notify">Проверить подключение</a>
            </div>
            <p class="small muted" style="margin-top:10px">Не открылось? Найдите в MAX бота <b><?= e(config('max.bot_username')) ?></b> и отправьте ему <code>/start <?= e($user['max_link_code']) ?></code> — код привязывает бота к вашему кабинету, не передавайте его другим.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($tgEnabled): ?>
        <div class="notify-ch">
          <div class="notify-ch-head"><b>Telegram</b></div>
          <div class="alert alert-warn small">Из-за ограничений и блокировок Telegram в России уведомления могут приходить с задержкой или не приходить. Для надёжности подключите MAX.</div>
          <?php if ($user['tg_chat_id']): ?>
            <div class="alert alert-success">Telegram подключён — уведомления приходят в чат с ботом @<?= e(config('telegram.bot_username')) ?>.</div>
            <div class="row">
              <form method="post" action="/app/settings/telegram/test"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Отправить тест</button></form>
              <form method="post" action="/app/settings/telegram/unlink"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Отключить</button></form>
            </div>
          <?php else: ?>
            <ol class="tg-steps">
              <li>Нажмите <b>«Подключить Telegram»</b> — откроется бот <a href="https://t.me/<?= e(config('telegram.bot_username')) ?>" target="_blank" rel="noopener">t.me/<?= e(config('telegram.bot_username')) ?></a> (на компьютере — в Telegram Desktop или веб-версии).</li>
              <li>В чате с ботом нажмите <span class="kbd">Запустить</span> (Start).</li>
              <li>Подождите до минуты и нажмите «Проверить подключение».</li>
            </ol>
            <div class="row">
              <a class="btn btn-ghost" href="<?= e(Telegram::linkUrl((string) $user['tg_link_code'])) ?>" target="_blank" rel="noopener"><?= icon('send', 16) ?> Подключить Telegram</a>
              <a class="btn btn-ghost" href="/app/settings#notify">Проверить подключение</a>
            </div>
            <p class="small muted" style="margin-top:10px">Не открылось? Найдите в Telegram <b>@<?= e(config('telegram.bot_username')) ?></b> и отправьте ему <code>/start <?= e($user['tg_link_code']) ?></code>.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($user['tg_chat_id'] || $user['max_user_id']): ?>
        <form method="post" action="/app/settings/telegram" class="notify-ch">
          <?= csrf_field() ?>
          <label>О чём уведомлять</label>
          <div class="row" style="margin-bottom:14px">
            <?php foreach (SettingsController::TG_EVENTS as $k => $label): ?>
              <label class="check"><input type="checkbox" name="events[]" value="<?= $k ?>" <?= in_array($k, $events, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
          </div>
          <?php if (count($myStores) > 1): ?>
            <input type="hidden" name="tg_stores_on" value="1">
            <label>По каким магазинам</label>
            <div class="row" style="margin-bottom:14px">
              <?php foreach ($myStores as $s): ?>
                <label class="check"><input type="checkbox" name="tg_stores[]" value="<?= (int) $s['id'] ?>" <?= $tgStores === null || in_array((int) $s['id'], $tgStores, true) ? 'checked' : '' ?>> <?= e($s['name']) ?></label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <button class="btn btn-sm">Сохранить</button>
          <span class="small muted">Настройки общие для MAX и Telegram.</span>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if (!App\Core\Auth::isMember()): $teamLimit = (int) ($plan['team'] ?? 0); ?>
  <div class="card" id="team">
    <div class="card-title"><?= icon('users', 18) ?> Сотрудники — доступ ко всему кабинету по логину и паролю</div>
    <p class="muted small">Сотрудник получает доступ ко всем магазинам кабинета: видит ленту заказов, товары и финансы.
      Уровень <b>«Только просмотр»</b> ничего не меняет; <b>«Ведёт учёт»</b> — себестоимость, налог, НДС и расходы.
      Магазины, токены, тариф, загрузку истории и общие параметры расчёта меняете только вы.
      <b>Клиенту</b> доступ к его магазину открывается в разделе <a href="/app/stores">«Магазины»</a> — по email, в карточке магазина.
      <?php if ($teamLimit >= 100): ?>На вашем тарифе — без ограничений.<?php elseif ($teamLimit > 0): ?>Доступно на тарифе: <b><?= $teamLimit ?></b> <?= plural($teamLimit, 'сотрудник', 'сотрудника', 'сотрудников') ?>.<?php endif; ?></p>
    <?php if ($members): ?>
      <div class="table-wrap" style="margin-bottom:16px"><table class="table">
        <thead><tr><th>Логин</th><th>Имя</th><th>Доступ</th><th>Последний вход</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($members as $m): $acc = App\Services\StoreService::memberAccess($m + ['owner_id' => 1]);
          $accNames = $acc === null ? 'все' : implode(', ', array_column(array_filter($allStores, static fn($s) => in_array((int) $s['id'], $acc, true)), 'name')); ?>
          <tr>
            <td><b><?= e($m['email']) ?></b><div class="small muted">ID <?= e(client_code((int) $m['id'])) ?></div></td>
            <td><?= e($m['name']) ?></td>
            <td class="small">
              <details class="access-edit">
                <summary><?= e($accNames !== '' ? $accNames : 'нет доступа') ?> · <span class="<?= ($m['member_role'] ?? '') === 'client' ? '' : 'muted' ?>"><?= ($m['member_role'] ?? '') === 'client' ? 'ведёт учёт' : 'просмотр' ?></span></summary>
                <form method="post" action="/app/team/<?= (int) $m['id'] ?>/access">
                  <?= csrf_field() ?>
                  <?php if ($acc !== null): // доступ к отдельным магазинам — выдан раньше; новым клиентам доступ открывается в карточке магазина ?>
                    <?= $accessFields($acc, $allStores, (string) $m['id']) ?>
                  <?php else: ?><input type="hidden" name="access" value="all"><?php endif; ?>
                  <?= $roleField((string) ($m['member_role'] ?? 'viewer')) ?>
                  <button class="btn btn-sm" style="margin-top:8px">Сохранить доступ</button>
                </form>
              </details>
            </td>
            <td class="small muted nowrap"><?= date_ru($m['last_login_at']) ?></td>
            <td>
              <div class="row" style="gap:6px;justify-content:flex-end">
                <form method="post" action="/app/team/<?= (int) $m['id'] ?>/password" class="row" style="gap:6px">
                  <?= csrf_field() ?>
                  <input type="text" name="password" placeholder="Новый пароль" minlength="8" required style="width:150px;padding:6px 10px" autocomplete="new-password">
                  <button class="btn btn-ghost btn-sm">Сменить</button>
                </form>
                <?php if ($acc !== null && $acc): ?>
                  <form method="post" action="/app/team/<?= (int) $m['id'] ?>/transfer"
                    data-confirm="Передать <?= e($m['email']) ?> отдельный аккаунт вместе с магазинами: <?= e($accNames) ?>? Новый аккаунт сразу перейдёт на тариф «<?= e(App\Services\Plans::get('pro')['name']) ?>» на месяц с сегодняшнего дня<?= $transferPrice > 0 ? ', передача стоит ' . e(rub($transferPrice)) : ' (для вас — бесплатно)' ?>. Оплаченный остаток за эти магазины в вашем кабинете сгорит.">
                    <?= csrf_field() ?><button class="btn btn-ghost btn-sm" title="Выделить в отдельный аккаунт на тарифе «<?= e(App\Services\Plans::get('pro')['name']) ?>»">Передать аккаунт</button>
                  </form>
                <?php endif; ?>
                <form method="post" action="/app/team/<?= (int) $m['id'] ?>/delete" data-confirm="Удалить доступ сотрудника <?= e($m['email']) ?>?"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Удалить</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <?php if (count($members) < $teamLimit): ?>
      <form method="post" action="/app/team">
        <?= csrf_field() ?>
        <div class="row" style="align-items:flex-start">
          <div class="field grow" style="min-width:180px"><label for="tlogin">Логин</label><input id="tlogin" type="text" name="login" required placeholder="manager1 или email" autocapitalize="none"></div>
          <div class="field grow" style="min-width:160px"><label for="tname">Имя</label><input id="tname" type="text" name="name" placeholder="Анна, менеджер"></div>
          <div class="field grow" style="min-width:160px"><label for="tpass">Пароль</label><input id="tpass" type="text" name="password" minlength="8" required autocomplete="new-password"></div>
        </div>
        <div style="max-width:520px;margin-bottom:14px"><?= $roleField('viewer') ?></div>
        <button class="btn btn-sm"><?= icon('plus', 14) ?> Добавить сотрудника</button>
        <div class="hint small muted" style="margin-top:8px">Вход: <?= e(url('/login')) ?> — логин и пароль, которые вы задали.</div>
      </form>
    <?php elseif ($teamLimit === 0): ?>
      <a class="btn btn-sm" href="/app/billing">Доступно на тарифе <?= e(App\Services\Plans::namesWith('team')) ?></a>
    <?php else: ?>
      <p class="small muted">Лимит сотрудников по тарифу исчерпан. <a href="/app/billing">Увеличить</a></p>
    <?php endif; ?>
  </div>

  <?php if ($teamLimit > 0 || $members): ?>
  <div class="card" id="contacts">
    <div class="card-title"><?= icon('send', 18) ?> Ваши контакты для клиентов</div>
    <p class="muted small">Сотрудники и клиенты вашего кабинета видят эти контакты в настройках и профиле, в запросе «Подключить ещё магазин»
      и в напоминании, когда подходит срок продления. Пишут вам — подключение и продление идут через вас, а не через оплату в сервисе.</p>
    <form method="post" action="/app/settings/contacts">
      <?= csrf_field() ?>
      <div class="row" style="align-items:flex-start">
        <div class="field grow" style="min-width:200px"><label for="cname">Имя или название</label><input id="cname" type="text" name="name" maxlength="120" value="<?= e($contacts['name'] ?? '') ?>" placeholder="Агентство «Старт», менеджер Анна"></div>
        <div class="field grow" style="min-width:180px"><label for="cphone">Телефон</label><input id="cphone" type="tel" name="phone" maxlength="120" value="<?= e($contacts['phone'] ?? '') ?>" placeholder="+7 900 123-45-67"></div>
      </div>
      <div class="row" style="align-items:flex-start">
        <div class="field grow" style="min-width:160px"><label for="ctg">Telegram</label><input id="ctg" type="text" name="telegram" maxlength="120" value="<?= e($contacts['telegram'] ?? '') ?>" placeholder="@username" autocapitalize="none"></div>
        <div class="field grow" style="min-width:160px"><label for="cmax">MAX <span class="muted small">(ссылка или номер)</span></label><input id="cmax" type="text" name="max" maxlength="120" value="<?= e($contacts['max'] ?? '') ?>" placeholder="https://max.ru/…" autocapitalize="none"></div>
        <div class="field grow" style="min-width:180px"><label for="cemail">Email</label><input id="cemail" type="email" name="email" maxlength="120" value="<?= e($contacts['email'] ?? '') ?>" placeholder="manager@example.ru"></div>
      </div>
      <div class="field"><label for="cnote">Комментарий для клиентов</label><textarea id="cnote" name="note" maxlength="300" rows="2" placeholder="Например: отвечаем с 10 до 19, продление — по счёту"><?= e($contacts['note'] ?? '') ?></textarea></div>
      <button class="btn btn-sm">Сохранить контакты</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card" id="agency">
    <div class="card-title"><?= icon('shield', 18) ?> Доступ агентству</div>
    <p class="muted small">Откройте менеджеру или агентству доступ к своему кабинету по его ID — как в WB Partners. Агентство увидит ленту, товары и финансы
      и переключится в ваш кабинет в своём меню. Оплата тарифа остаётся за вами, доступ можно закрыть в любой момент. Ваш ID: <b><?= e(client_code((int) $user['id'])) ?></b>.</p>
    <?php if ($grantsGiven): ?>
      <div class="table-wrap" style="margin-bottom:14px"><table class="table">
        <thead><tr><th>Агентство</th><th>Доступ</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($grantsGiven as $g): $active = !$g['expires_at'] || strtotime($g['expires_at']) > time(); ?>
          <tr>
            <td><b><?= e($g['name'] ?: $g['email']) ?></b><div class="small muted">ID <?= e(client_code((int) $g['agency_id'])) ?></div></td>
            <td class="small"><?= $active ? ($g['expires_at'] ? 'до ' . date_ru($g['expires_at'], false) : 'бессрочно') : '<span class="badge badge-cancel">истёк ' . date_ru($g['expires_at'], false) . '</span>' ?></td>
            <td style="text-align:right"><form method="post" action="/app/grants/<?= (int) $g['agency_id'] ?>/delete" data-confirm="Закрыть доступ агентству ID <?= e(client_code((int) $g['agency_id'])) ?>?"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Закрыть доступ</button></form></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <form method="post" action="/app/grants" class="row" style="align-items:flex-end">
      <?= csrf_field() ?>
      <div class="field" style="margin:0"><label for="agid">ID агентства</label><input id="agid" type="text" name="agency" inputmode="numeric" placeholder="000123" required style="width:140px"></div>
      <div class="field" style="margin:0"><label for="aguntil">Доступ до <span class="muted small">(необязательно)</span></label><input id="aguntil" type="date" name="until" min="<?= date('Y-m-d') ?>"></div>
      <button class="btn btn-sm">Открыть доступ</button>
    </form>
  </div>
  <?php elseif (App\Core\Auth::acting() && App\Core\Auth::actingKind() !== 'client'): ?>
  <div class="alert alert-info">Вы работаете в кабинете клиента <b><?= e($account['name'] ?: $account['email']) ?></b>.</div>
  <?php else: $isClient = ($user['member_role'] ?? '') === 'client' || App\Core\Auth::actingKind() === 'client'; ?>
  <div class="card" id="request">
    <div class="card-title"><?= icon('users', 18) ?> Ваш кабинет ведёт <?= e(App\Services\Contacts::of($owner)['name'] ?? ($account['name'] ?: $account['email'])) ?></div>
    <p class="muted small">Вы вошли по доступу от владельца кабинета. Ваш уровень доступа: <b><?= $isClient ? 'клиент' : 'только просмотр' ?></b><?= $isClient
      ? ' — вы сами ведёте себестоимость, налог и расходы своих магазинов.' : ' — изменения вносит владелец кабинета.' ?>
      Подключение магазинов и продление — через владельца кабинета, напишите ему:</p>
    <div class="contact-box"><?= $owner ? App\Services\Contacts::html($owner) : '' ?></div>
    <?php if ($myRequest): ?>
      <div class="alert alert-success" style="margin-top:12px">Запрос «<?= $myRequest['kind'] === 'renew' ? 'Продлить доступ' : 'Подключить ещё магазин' ?>» отправлен <?= date_ru($myRequest['created_at']) ?>. Владелец кабинета свяжется с вами.</div>
    <?php else: ?>
      <form method="post" action="/app/settings/request" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="kind" value="store">
        <div class="field"><label for="rmsg">Какой магазин подключить <span class="muted small">(необязательно)</span></label>
          <input id="rmsg" type="text" name="message" maxlength="500" placeholder="Например: ИП Иванов, второй кабинет WB"></div>
        <button class="btn btn-sm"><?= icon('plus', 14) ?> Подключить ещё магазин</button>
        <div class="hint small muted" style="margin-top:6px">Запрос придёт владельцу кабинета. Токен WB не пишите в комментарии — передайте его владельцу лично.</div>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($grantsReceived): ?>
  <div class="card" id="clients">
    <div class="card-title"><?= icon('users', 18) ?> Кабинеты клиентов</div>
    <p class="muted small">Клиенты открыли вашему агентству доступ к своим кабинетам. Переключайтесь между ними здесь или в меню «Кабинет».</p>
    <?php foreach ($grantsReceived as $g): ?>
      <form method="post" action="/app/switch" class="top-item">
        <?= csrf_field() ?><input type="hidden" name="account" value="<?= (int) $g['owner_id'] ?>">
        <div class="grow"><b><?= e($g['name'] ?: $g['email']) ?></b><div class="small muted">ID <?= e(client_code((int) $g['owner_id'])) ?> · <?= $g['expires_at'] ? 'доступ до ' . date_ru($g['expires_at'], false) : 'бессрочный доступ' ?></div></div>
        <button class="btn btn-ghost btn-sm"><?= App\Core\Auth::acting() === (int) $g['owner_id'] ? 'Открыт' : 'Открыть' ?></button>
      </form>
    <?php endforeach; ?>
    <?php if (App\Core\Auth::acting()): ?>
      <form method="post" action="/app/switch" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="account" value="0"><button class="btn btn-sm">Вернуться в свой кабинет</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (($user['role'] ?? '') === 'admin'): ?>
  <a class="card settings-link" href="/app/settings/api">
    <span class="logo-mark" style="width:40px;height:40px"><?= icon('zap', 20) ?></span>
    <span class="grow"><b>API и подключение к Claude</b><span class="muted small" style="display:block">Ключ доступа: Claude сам смотрит заказы, прибыль и состояние синхронизации. Только чтение.</span></span>
    <?= icon('chevron', 18) ?>
  </a>
  <?php endif; ?>

  <div class="card" id="profile">
    <div class="card-title">Профиль</div>
    <?php if (App\Core\Auth::needsPhone($user)): ?>
      <div class="alert alert-warn">Укажите номер телефона — он нужен для связи по тарифу и поддержке.</div>
    <?php endif; ?>
    <form method="post" action="/app/settings/profile">
      <?= csrf_field() ?>
      <div class="field"><label>Логин</label><input type="text" value="<?= e($user['email']) ?>" disabled></div>
      <div class="field"><label for="name">Имя</label><input id="name" type="text" name="name" value="<?= e($user['name']) ?>"></div>
      <div class="field"><label for="phone">Телефон</label><input id="phone" type="tel" name="phone" value="<?= e(phone_ru($user['phone'] ?? '')) ?>" inputmode="tel" autocomplete="tel" placeholder="+7 900 123-45-67" <?= empty($user['owner_id']) && ($user['role'] ?? '') !== 'admin' ? 'required' : '' ?>></div>
      <button class="btn btn-sm">Сохранить</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title">Смена пароля</div>
    <form method="post" action="/app/settings/password">
      <?= csrf_field() ?>
      <div class="field"><label for="cur">Текущий пароль</label><input id="cur" type="password" name="current" required autocomplete="current-password"></div>
      <div class="field"><label for="np">Новый пароль</label><input id="np" type="password" name="password" minlength="8" required autocomplete="new-password"></div>
      <button class="btn btn-sm">Изменить пароль</button>
    </form>
  </div>

  <?php if (!App\Core\Auth::isMember()): ?>
  <div class="card" id="currency">
    <div class="card-title">Валюта сумм</div>
    <form method="post" action="/app/settings/currency" class="stack">
      <?= csrf_field() ?><input type="hidden" name="back" value="/app/settings#currency">
      <label class="check"><input type="radio" name="show_rub" value="0" <?= empty($user['show_rub']) ? 'checked' : '' ?>> В валюте магазина — как в кабинете WB (₽, сомы, тенге…)</label>
      <label class="check"><input type="radio" name="show_rub" value="1" <?= !empty($user['show_rub']) ? 'checked' : '' ?>> В рублях по курсу ЦБ РФ — лента по курсу на сегодня, финансы — по курсу на конец каждой недели или месяца</label>
      <div><button class="btn btn-sm">Сохранить</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title">Оформление</div>
    <button class="btn btn-ghost" id="themeBtn" type="button"><?= icon('moon', 16) ?> Переключить светлую / тёмную тему</button>
  </div>
</div>
<script>
  // «Только выбранные магазины» — показываем список магазинов
  document.querySelectorAll('[data-access]').forEach(function (box) {
    box.addEventListener('change', function () {
      var some = box.querySelector('input[name=access][value=some]').checked;
      box.querySelector('.access-list').classList.toggle('hidden', !some);
    });
  });
</script>
