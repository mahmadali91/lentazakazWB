<div class="topbar"><h1>Настройки сервиса</h1></div>
<?= App\Core\View::partial('admin/_nav', ['tab' => 'settings']) ?>

<form method="post" action="/admin/settings" class="stack page-narrow">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-title"><?= icon('shield', 18) ?> Реквизиты исполнителя</div>
    <p class="muted small">Подставляются автоматически в <a href="/oferta" target="_blank">оферту</a>, <a href="/privacy" target="_blank">политику конфиденциальности</a> и подвал сайта.</p>
    <div class="field"><label for="cn">ФИО или наименование</label><input id="cn" type="text" name="company_name" value="<?= e($company['name']) ?>" placeholder="ИП Иванов Иван Иванович или ООО «Линкоз»" maxlength="200"></div>
    <div class="row" style="align-items:flex-start">
      <div class="field grow"><label for="ci">ИНН</label><input id="ci" type="text" inputmode="numeric" name="company_inn" value="<?= e($company['inn']) ?>" placeholder="12 цифр для ИП, 10 — для ООО" maxlength="12"></div>
      <div class="field grow"><label for="co">ОГРНИП / ОГРН <span class="muted small">(необязательно; самозанятым не нужно)</span></label><input id="co" type="text" inputmode="numeric" name="company_ogrn" value="<?= e($company['ogrn']) ?>" placeholder="15 или 13 цифр" maxlength="15"></div>
    </div>
    <div class="field"><label for="ca">Адрес <span class="muted small">(для ИП можно указать город)</span></label><input id="ca" type="text" name="company_address" value="<?= e($company['address']) ?>" maxlength="300"></div>
    <div class="field"><label for="cb">Банковские реквизиты <span class="muted small">(необязательно)</span></label><textarea id="cb" name="company_bank" maxlength="500" style="font-family:inherit;font-size:14px;min-height:64px" placeholder="Р/с, банк, БИК, к/с"><?= e($company['bank']) ?></textarea></div>
  </div>

  <div class="card">
    <div class="card-title"><?= icon('mail', 18) ?> Контакты для клиентов</div>
    <p class="muted small">Показываются в подвале сайта, в профиле клиента («Написать в поддержку»), на странице тарифов и в документах.</p>
    <div class="row" style="align-items:flex-start">
      <div class="field grow"><label for="sp">Телефон поддержки</label><input id="sp" type="tel" name="support_phone" value="<?= e(phone_ru($phone)) ?>" placeholder="+7 900 123-45-67" inputmode="tel"></div>
      <div class="field grow"><label for="se">Email поддержки</label><input id="se" type="email" name="support_email" value="<?= e($email) ?>" placeholder="admin@linkoz.ru"></div>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><?= icon('send', 18) ?> Telegram-бот для уведомлений</div>
    <?php if ($bot): ?>
      <?php if ($bot['ok']): ?>
        <div class="alert alert-success">Бот работает: <b><?= e($bot['name']) ?></b> — <a href="https://t.me/<?= e($bot['username']) ?>" target="_blank" rel="noopener">@<?= e($bot['username']) ?></a>. Подключили уведомления: <?= (int) $bot['linked'] ?>.
          <?php if ($bot['webhook']): ?><br>Внимание: у бота установлен вебхук — привязка аккаунтов не будет работать. Сохраните токен ещё раз, чтобы снять вебхук.<?php endif; ?></div>
      <?php else: ?>
        <div class="alert alert-error">Telegram не отвечает на этот токен (getMe): <?= e($bot['error']) ?>. Проверьте токен в @BotFather и сохраните заново.</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="alert alert-warn">Бот не подключён — клиенты не смогут включить уведомления о заказах.</div>
    <?php endif; ?>
    <div class="field"><label for="bu">Имя бота (username)</label><input id="bu" type="text" name="bot_username" value="<?= e($botUser) ?>" placeholder="WBLinkoz_bot"></div>
    <div class="field"><label for="bt">Токен бота</label><input id="bt" type="password" name="bot_token" value="" autocomplete="off" placeholder="<?= $hasToken ? 'сохранён — оставьте пустым, чтобы не менять' : '123456789:AA…' ?>">
      <div class="hint">Из @BotFather («Use this token to access the HTTP API»). Хранится в базе в зашифрованном виде и больше не показывается. При сохранении проверяем его в Telegram.</div></div>
    <?php if ($hasToken): ?><label class="check"><input type="checkbox" name="bot_remove" value="1"> отключить бота (удалить токен)</label><?php endif; ?>
    <details class="inline" style="margin-top:10px">
      <summary>Как создать бота</summary>
      <ol class="small">
        <li>Откройте <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> → <span class="kbd">/newbot</span>, задайте название (например «WBLinkoz бот») и имя, оканчивающееся на <code>bot</code>.</li>
        <li>Скопируйте токен и вставьте выше. Имя бота подставится автоматически.</li>
        <li>Клиенты подключают уведомления в «Настройки» → «Уведомления в Telegram»: кнопка откроет бота, после «Запустить» аккаунт привяжется в течение минуты (бот опрашивается вместе с cron).</li>
      </ol>
    </details>
  </div>
  <div class="card">
    <div class="card-title"><?= icon('card', 18) ?> Оплата тарифов — ЮKassa</div>
    <?php if ($ykOk): ?>
      <div class="alert <?= $ykOk['ok'] ? 'alert-success' : 'alert-error' ?>"><?= e($ykOk['text']) ?></div>
    <?php else: ?>
      <div class="alert alert-warn">ЮKassa не подключена — клиенты не могут оплатить тариф онлайн, тарифы выдаёте вручную в карточке клиента.</div>
    <?php endif; ?>
    <div class="row" style="align-items:flex-start">
      <div class="field grow"><label for="yks">shopId</label><input id="yks" type="text" inputmode="numeric" name="yk_shop_id" value="<?= e($ykShop) ?>" placeholder="123456"></div>
      <div class="field grow"><label for="ykk">Секретный ключ</label><input id="ykk" type="password" name="yk_secret" value="" autocomplete="off" placeholder="<?= $ykHasKey ? 'сохранён — оставьте пустым, чтобы не менять' : 'live_… или test_…' ?>"></div>
    </div>
    <div class="row" style="align-items:flex-start">
      <label class="check" style="margin:0"><input type="checkbox" name="yk_receipt" value="1" <?= $ykReceipt ? 'checked' : '' ?>> передавать чек (54-ФЗ) — если подключена онлайн-касса ЮKassa</label>
      <div class="field" style="min-width:220px"><label for="ykv">Ставка НДС в чеке</label>
        <select id="ykv" name="yk_vat">
          <?php foreach ([1 => 'Без НДС', 2 => '0%', 3 => '10%', 4 => '20%', 7 => '5%', 8 => '7%'] as $code => $label): ?>
            <option value="<?= $code ?>" <?= $ykVat === $code ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <?php if ($ykHasKey): ?><label class="check"><input type="checkbox" name="yk_remove" value="1"> отключить ЮKassa (удалить ключи)</label><?php endif; ?>
    <details class="inline" style="margin-top:10px">
      <summary>Как подключить</summary>
      <ol class="small">
        <li>В личном кабинете <a href="https://yookassa.ru/my" target="_blank" rel="noopener">ЮKassa</a> → «Интеграция» → «Ключи API»: скопируйте <b>shopId</b> и выпустите <b>секретный ключ</b>.</li>
        <li>«Интеграция» → «HTTP-уведомления»: URL <code><?= e(url('/webhook/yookassa')) ?></code>, события <b>payment.succeeded</b> и <b>payment.canceled</b>.</li>
        <li>Вставьте shopId и ключ выше и сохраните — сервис проверит их запросом к ЮKassa. Ключ хранится в базе в зашифрованном виде.</li>
      </ol>
    </details>
  </div>
  <button class="btn">Сохранить настройки</button>
</form>
