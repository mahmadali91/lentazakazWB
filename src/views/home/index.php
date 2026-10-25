<?php
$demo = [
    ['Платье летнее миди', 'арт. DR-2041 · 186452311', 'FBO · склад WB', 'Коледино → Казань', 'created', 2890, '2 мин назад'],
    ['Кроссовки беговые', 'арт. RUN-77 · 154422190', 'FBS', 'Склад продавца → Москва', 'created', 5490, '6 мин назад'],
    ['Набор посуды 12 предметов', 'арт. KIT-12 · 99814522', 'DBS', 'Склад продавца → Санкт-Петербург', 'buyout', 3150, '18 мин назад'],
    ['Рюкзак городской', 'арт. BP-9 · 120987345', 'FBO · склад WB', 'Электросталь → Екатеринбург', 'cancel', 2240, '25 мин назад'],
];
?>
<section class="hero">
  <div class="container">
    <span class="badge badge-accent"><?= icon('zap', 14) ?> Данные из официального API Wildberries</span>
    <h1 style="margin-top:18px">Все заказы <span>Wildberries</span> в одной живой ленте</h1>
    <p class="lead">Видите каждый заказ в течение 5 минут после оформления: FBO, FBS, DBS, DBW и самовывоз в одном окне. Уведомления в Telegram, выкупы, отмены и возвраты, выгрузка в Excel.</p>
    <div class="row">
      <a class="btn btn-lg" href="/register">Начать бесплатно</a>
      <a class="btn btn-lg btn-ghost" href="#how">Как это работает</a>
    </div>
    <div class="hero-note"><?= (int) config('app.trial_days', 7) ?> дней полного доступа бесплатно · без привязки карты</div>

    <div class="mock">
      <div class="card">
        <div class="mock-bar"><i></i><i></i><i></i></div>
        <?php foreach ($demo as [$t, $meta, $scheme, $route, $status, $price, $time]): ?>
          <div class="order">
            <div class="thumb"><?= icon('image', 22) ?></div>
            <div style="min-width:0">
              <div class="o-title"><?= e($t) ?></div>
              <div class="o-meta"><span><?= e($meta) ?></span><span><?= e($route) ?></span></div>
              <div class="o-badges"><span class="badge badge-<?= $status ?>"><?= status_label($status) ?></span><span class="badge badge-scheme"><?= e($scheme) ?></span></div>
            </div>
            <div class="o-right"><div class="o-price"><?= money($price) ?></div><div class="o-time"><?= e($time) ?></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<section class="section" id="features">
  <div class="container">
    <h2>Всё, что нужно продавцу</h2>
    <p class="sub">Не нужно держать открытым кабинет продавца и обновлять страницу: заказы приходят сами.</p>
    <div class="grid grid-3">
      <div class="card feature"><div class="ico"><?= icon('zap') ?></div><h3>Реальное время</h3><p>Используем официальный метод WB «Лента заказов»: новые заказы, выкупы, отмены и возвраты появляются в течение минуты.</p></div>
      <div class="card feature"><div class="ico"><?= icon('box') ?></div><h3>Все схемы работы</h3><p>FBO (склад WB), FBS, DBS, DBW и самовывоз. Схема определяется автоматически по сборочным заданиям.</p></div>
      <div class="card feature"><div class="ico"><?= icon('send') ?></div><h3>Уведомления в Telegram</h3><p>Новый заказ — сразу сообщение с товаром, ценой, складом и городом. Можно включить выкупы, отмены и возвраты.</p></div>
      <div class="card feature"><div class="ico"><?= icon('chart') ?></div><h3>Сводка и график</h3><p>Заказы и сумма за день, неделю или месяц, процент выкупа, отмены, топ товаров и график по часам.</p></div>
      <div class="card feature"><div class="ico"><?= icon('store') ?></div><h3>Несколько магазинов</h3><p>Подключите все свои кабинеты WB и смотрите общую ленту или каждый магазин отдельно.</p></div>
      <div class="card feature"><div class="ico"><?= icon('lock') ?></div><h3>Безопасно</h3><p>Токен WB хранится в зашифрованном виде и нужен только для чтения заказов. Удалить его можно в один клик.</p></div>
    </div>
    <div style="margin-top:36px" class="schemes">
      <span class="badge badge-scheme">FBO · склад WB</span>
      <span class="badge badge-scheme">FBS · склад продавца</span>
      <span class="badge badge-scheme">DBS · доставка продавцом</span>
      <span class="badge badge-scheme">DBW · доставка WB со склада продавца</span>
      <span class="badge badge-scheme">Самовывоз (Click&amp;Collect)</span>
      <span class="badge badge-scheme">B2B-заказы</span>
    </div>
  </div>
</section>

<section class="section" id="how" style="background:var(--surface);border-top:1px solid var(--border);border-bottom:1px solid var(--border)">
  <div class="container">
    <h2>Подключение за 3 минуты</h2>
    <p class="sub">Никаких программ и настроек: нужен только токен из кабинета продавца.</p>
    <div class="grid grid-3 steps">
      <div class="step"><h3>Зарегистрируйтесь</h3><p class="muted">Email и пароль — пробный период включится автоматически.</p></div>
      <div class="step"><h3>Создайте токен WB</h3><p class="muted">Кабинет продавца → Настройки → Доступ к API. Отметьте категории «Аналитика», «Статистика», «Маркетплейс», «Контент» и режим «Только чтение».</p></div>
      <div class="step"><h3>Смотрите ленту</h3><p class="muted">Вставьте токен — через несколько минут заказы появятся в ленте, а уведомления придут в Telegram.</p></div>
    </div>
  </div>
</section>

<section class="section" id="pricing">
  <div class="container">
    <h2>Тарифы</h2>
    <p class="sub">Начните с бесплатного пробного периода. Скидка до 20% при оплате за несколько месяцев.</p>
    <div class="plans">
      <?php foreach ($plans as $code => $p): ?>
        <div class="card plan <?= !empty($p['popular']) ? 'popular' : '' ?>">
          <?php if (!empty($p['popular'])): ?><span class="badge badge-accent tag">Популярный</span><?php endif; ?>
          <div class="plan-name"><?= e($p['name']) ?></div>
          <div class="plan-price"><?= $p['price'] > 0 ? number_format($p['price'], 0, ',', ' ') . ' ₽' : '0 ₽' ?> <small>/ мес</small></div>
          <ul>
            <?php foreach ($p['features'] as $feat): ?><li><?= e($feat) ?></li><?php endforeach; ?>
          </ul>
          <a class="btn <?= !empty($p['popular']) ? '' : 'btn-ghost' ?> btn-block" href="/register"><?= $p['price'] > 0 ? 'Попробовать' : 'Начать' ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" id="faq">
  <div class="container" style="max-width:820px">
    <h2>Частые вопросы</h2>
    <div class="faq" style="margin-top:28px">
      <details><summary>Откуда берутся данные?</summary><p>Только из официального API Wildberries (dev.wildberries.ru) по вашему токену: «Лента заказов» (реальное время), «Статистика», сборочные задания «Маркетплейса» и карточки товаров «Контента».</p></details>
      <details><summary>Это безопасно? Сервис может что-то изменить в кабинете?</summary><p>Создайте токен в режиме «Только чтение» — тогда через него невозможно ничего изменить. Токен хранится в зашифрованном виде, его можно удалить в любой момент.</p></details>
      <details><summary>Какой тип токена выбрать?</summary><p>«Персональный» (или «Сервисный», если сервис есть в каталоге решений WB). С «Базовым» токеном WB разрешает запрашивать ленту только раз в 3 часа.</p></details>
      <details><summary>Почему заказ FBO показывается как «Склад WB», а не конкретный склад?</summary><p>Так отдаёт «Лента заказов» WB. Название склада подтягивается из «Статистики» примерно через 30 минут после заказа.</p></details>
      <details><summary>Как быстро появляются заказы?</summary><p>Сервис обновляет ленту из WB каждые 5 минут, поэтому новый заказ появляется в течение 5 минут. Если WB временно ограничит запросы, сервис подождёт и продолжит сам.</p></details>
      <details><summary>Как оплатить?</summary><p>Банковской картой или СБП через ЮKassa прямо в личном кабинете. Тариф включается сразу после оплаты.</p></details>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="container" style="text-align:center">
    <div class="card" style="padding:40px 20px;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;border:0">
      <h2 style="color:#fff">Попробуйте бесплатно <?= (int) config('app.trial_days', 7) ?> дней</h2>
      <p style="opacity:.9;margin-bottom:22px">Подключите магазин за 3 минуты и посмотрите на свои заказы по-новому.</p>
      <a class="btn btn-lg" style="background:#fff;color:#4c1d95" href="/register">Создать аккаунт</a>
    </div>
  </div>
</section>
