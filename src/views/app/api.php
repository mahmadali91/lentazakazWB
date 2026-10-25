<?php
$mcpUrl = static fn(string $key) => $baseUrl . '/mcp/' . $key;
?>
<div class="topbar"><h1>API и Claude</h1></div>

<div class="stack page-narrow">
  <div class="card">
    <p style="margin-top:0">Подключите сервис к <b>Claude</b> — и спрашивайте прямо в чате: «какая чистая прибыль за октябрь?», «какие товары убыточные?», «почему не обновляются заказы?». Claude сам читает данные кабинета через MCP-сервер. Доступ <b>только на чтение</b>: через ключ нельзя ничего изменить.</p>
    <p class="muted small" style="margin-bottom:0">Тот же ключ подходит для REST API (JSON) — для своих скриптов, Google Таблиц, Claude Code.</p>
  </div>

  <?php if ($isMember): ?>
    <div class="alert alert-info">Ключи API создаёт владелец кабинета.</div>
  <?php else: ?>

  <?php if ($newKey): ?>
    <div class="card api-new">
      <div class="card-title"><?= icon('check', 18) ?> Ключ «<?= e($newKey['name']) ?>» создан</div>
      <p class="small"><b>Скопируйте адрес сейчас</b> — ключ показывается один раз. Если потеряете, просто создайте новый.</p>
      <label>Адрес MCP-сервера для Claude</label>
      <div class="copy-row"><code class="mono"><?= e($mcpUrl($newKey['key'])) ?></code><button type="button" class="btn btn-sm" data-copy="<?= e($mcpUrl($newKey['key'])) ?>">Копировать</button></div>
      <label style="margin-top:12px">Ключ API (для REST)</label>
      <div class="copy-row"><code class="mono"><?= e($newKey['key']) ?></code><button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($newKey['key']) ?>">Копировать</button></div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title">Ключи доступа</div>
    <?php if ($keys): ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Название</th><th>Ключ</th><th>Использован</th><th>Запросов</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($keys as $k): ?>
            <tr>
              <td><b><?= e($k['name']) ?></b><div class="muted small">создан <?= date_ru($k['created_at'], false) ?></div></td>
              <td class="mono">lz_<?= e($k['token_hint']) ?></td>
              <td class="small"><?= $k['last_used_at'] ? date_ru($k['last_used_at']) : '<span class="muted">ещё нет</span>' ?></td>
              <td><?= number_format((int) $k['requests'], 0, ',', ' ') ?></td>
              <td><form method="post" action="/app/api/keys/<?= (int) $k['id'] ?>/delete" data-confirm="Отозвать ключ? Подключения с ним перестанут работать."><?= csrf_field() ?><button class="btn btn-danger btn-sm">Отозвать</button></form></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted">Ключей пока нет.</p>
    <?php endif; ?>
    <form method="post" action="/app/api/keys" class="row" style="margin-top:12px;gap:8px">
      <?= csrf_field() ?>
      <input type="text" name="name" placeholder="Название, например Claude" maxlength="80" style="max-width:260px">
      <button class="btn btn-sm"><?= icon('plus', 16) ?> Создать ключ</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card guide">
    <div class="card-title">Как подключить к Claude</div>
    <p class="small"><b>claude.ai (сайт, приложение на телефоне и компьютере):</b></p>
    <ol>
      <li>Создайте ключ выше и скопируйте <b>адрес MCP-сервера</b> — <code><?= e($baseUrl) ?>/mcp/lz_…</code></li>
      <li>В Claude откройте <b>Настройки → Коннекторы</b> и нажмите <span class="kbd">Добавить пользовательский коннектор</span>.</li>
      <li>Название — «Лента заказов WB», URL — скопированный адрес. В «Аутентификации» выберите <b>«Без входа»</b>, строку заголовка удалите. Нажмите «Добавить».</li>
      <li class="muted">Вариант надёжнее: URL — <code><?= e($baseUrl) ?>/mcp</code>, а в «Заголовки запроса» добавьте <code>Authorization</code> со значением <code>Bearer lz_ВАШ_КЛЮЧ</code> — Claude хранит его скрыто.</li>
      <li>В новом чате включите коннектор (кнопка инструментов под полем ввода) и спросите, например: <i>«Проверь, работает ли синхронизация с WB»</i> или <i>«Покажи чистую прибыль по неделям и самые убыточные товары»</i>.</li>
    </ol>
    <p class="small"><b>Claude Code (терминал):</b></p>
    <div class="copy-row"><code class="mono">claude mcp add --transport http lenta-wb <?= e($baseUrl) ?>/mcp/lz_ВАШ_КЛЮЧ</code></div>
    <p class="small" style="margin-top:14px"><b>REST API:</b> все инструменты доступны по адресу <code><?= e($baseUrl) ?>/api/v1/&lt;инструмент&gt;</code>, параметры — в строке запроса или JSON. Список: <code>GET <?= e($baseUrl) ?>/api/v1</code>.</p>
    <div class="copy-row"><code class="mono">curl -H "Authorization: Bearer lz_ВАШ_КЛЮЧ" "<?= e($baseUrl) ?>/api/v1/finance_summary?period=w4"</code></div>
    <p class="muted small">Ключ даёт доступ к данным кабинета — не публикуйте его. Подозреваете утечку — отзовите ключ и создайте новый.</p>
  </div>

  <div class="card">
    <div class="card-title">Что умеет (инструменты)</div>
    <div class="src-list small">
      <?php foreach ($tools as $t): $params = array_keys((array) $t['inputSchema']['properties']); ?>
        <div><b class="mono" style="font-size:13px"><?= e($t['name']) ?></b> — <?= e($t['title']) ?>. <span class="muted"><?= e($t['description']) ?></span>
          <?php if ($params): ?><div class="muted">Параметры: <?= e(implode(', ', $params)) ?></div><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
