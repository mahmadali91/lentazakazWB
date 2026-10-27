<?php
$trial = (int) config('app.trial_days', 7);
$shot = static fn(string $name, string $alt, string $cls = '') => '<figure class="shot ' . $cls . '"><div class="mock-bar"><i></i><i></i><i></i></div>'
    . '<img src="/assets/img/screens/' . $name . '.webp" alt="' . e($alt) . '" loading="lazy" decoding="async"></figure>';
?>
<section class="hero">
  <div class="container">
    <span class="badge badge-accent"><?= icon('zap', 14) ?> Данные из официального API Wildberries</span>
    <h1 style="margin-top:18px">Все заказы <span>Wildberries</span> и чистая прибыль в одном окне</h1>
    <p class="lead">Живая лента заказов по всем схемам — FBO, FBS, DBS, DBW, самовывоз и B2B. Заказы и выкупы считаются так же, как в кабинете WB, а прибыль — по отчётам реализации. Уведомления в MAX и Telegram, несколько магазинов и валют.</p>
    <div class="row">
      <a class="btn btn-lg" href="/register">Начать бесплатно</a>
      <a class="btn btn-lg btn-ghost" href="#screens">Посмотреть сервис</a>
    </div>
    <div class="hero-note"><?= $trial ?> дней полного доступа бесплатно · без привязки карты</div>
    <div class="hero-shot"><?= $shot('feed-list', 'Лента заказов: заказы, выкупы, прибыль по выкупам') ?></div>
  </div>
</section>

<section class="stats-strip">
  <div class="container">
    <div><b>5 мин</b><span>новый заказ в ленте</span></div>
    <div><b>6 схем</b><span>FBO, FBS, DBS, DBW, C&amp;C, B2B</span></div>
    <div><b>1 в 1</b><span>заказы и выкупы как в кабинете WB</span></div>
    <div><b>₽ · с · ₸</b><span>учёт валюты каждого кабинета</span></div>
  </div>
</section>

<section class="section" id="screens">
  <div class="container">
    <h2>Как выглядит сервис</h2>
    <p class="sub">Реальные экраны сервиса на демонстрационных данных.</p>

    <div class="showcase">
      <div class="showcase-text">
        <span class="badge badge-accent">Лента заказов</span>
        <h3>Лентой или плиткой — как удобнее</h3>
        <p>Каждый заказ с крупным фото товара, схемой, складом, городом и ценой продавца. Переключайте вид одной кнопкой: подробная лента или плитка с карточками товаров.</p>
        <ul class="ticks">
          <li>Фильтры: сегодня, вчера, 7 и 30 дней, свой период, схема, магазин, поиск по артикулу</li>
          <li>Заказы — без отменённых, выкупы — по дате выкупа за вычетом возвратов, как в кабинете WB</li>
          <li><b>Прибыль по выкупам</b> прямо в шапке ленты, рядом — оценка по всем заказам</li>
        </ul>
      </div>
      <?= $shot('feed-tiles', 'Лента заказов в виде плитки') ?>
    </div>

    <div class="showcase rev">
      <div class="showcase-text">
        <span class="badge badge-accent">Финансы</span>
        <h3>Чистая прибыль по отчётам реализации</h3>
        <p>Еженедельные отчёты WB, платное хранение и реклама загружаются сами. Сервис считает выручку, комиссию, логистику, налог, себестоимость и показывает, сколько вы реально заработали.</p>
        <ul class="ticks">
          <li>Сводка, ОПИУ по неделям и месяцам, прибыль по каждому товару, ABC-анализ</li>
          <li>Детализация по заказу, хранение, реклама, прочие расходы</li>
          <li>Отчёты в разных валютах считаются отдельно — без смешивания рублей и сомов</li>
        </ul>
      </div>
      <?= $shot('finance', 'Финансы: сводка чистой прибыли') ?>
    </div>

    <div class="showcase">
      <div class="showcase-text">
        <span class="badge badge-accent">ОПИУ</span>
        <h3>Отчёт о прибылях и убытках без Excel</h3>
        <p>Те же строки, что в привычной таблице: продажи, возвраты, к перечислению, удержания WB, себестоимость, налог, реклама — по неделям или месяцам, с маржой и ROI.</p>
      </div>
      <?= $shot('opiu', 'ОПИУ по неделям') ?>
    </div>

    <div class="showcase rev">
      <div class="showcase-text">
        <span class="badge badge-accent">Товары</span>
        <h3>Прибыль и себестоимость каждого товара</h3>
        <p>Укажите себестоимость, налог и прочие расходы — сервис посчитает прибыль на единицу по тарифам WB для каждой схемы и покажет её в каждом заказе.</p>
      </div>
      <?= $shot('products', 'Товары и прибыль') ?>
    </div>

    <div class="showcase phones">
      <div class="showcase-text">
        <span class="badge badge-accent">Телефон и Telegram</span>
        <h3>Заказы всегда под рукой</h3>
        <p>Сайт удобно работает с телефона: лента, расчёт прибыли по заказу, фильтры. А бот <a href="https://t.me/<?= e((string) config('telegram.bot_username', 'WBLinkoz_bot')) ?>" target="_blank" rel="noopener">@<?= e((string) config('telegram.bot_username', 'WBLinkoz_bot')) ?></a> пришлёт сообщение о каждом новом заказе, выкупе, отмене или возврате — даже когда сайт закрыт.</p>
      </div>
      <div class="phone-pair">
        <figure class="phone"><img src="/assets/img/screens/mobile-feed.webp" alt="Лента заказов на телефоне" loading="lazy" decoding="async"></figure>
        <figure class="phone"><img src="/assets/img/screens/mobile-order.webp" alt="Расчёт прибыли по заказу на телефоне" loading="lazy" decoding="async"></figure>
      </div>
    </div>
  </div>
</section>

<section class="section" id="features" style="background:var(--surface);border-top:1px solid var(--border);border-bottom:1px solid var(--border)">
  <div class="container">
    <h2>Всё, что нужно продавцу</h2>
    <p class="sub">Не нужно держать открытым кабинет продавца и обновлять страницу: заказы и деньги считаются сами.</p>
    <div class="grid grid-features">
      <div class="card feature"><div class="ico"><?= icon('zap') ?></div><h3>Реальное время</h3><p>Официальный метод WB «Лента заказов»: новые заказы, выкупы, отмены и возвраты появляются в течение 5 минут.</p></div>
      <div class="card feature"><div class="ico"><?= icon('check') ?></div><h3>Цифры как в кабинете WB</h3><p>Заказы — по ленте WB без отменённых, выкупы — по дате выкупа минус возвраты. Количество совпадает с кабинетом.</p></div>
      <div class="card feature"><div class="ico"><?= icon('box') ?></div><h3>Все схемы работы</h3><p>FBO, FBS, DBS, DBW, самовывоз и B2B. Схема определяется автоматически по сборочным заданиям.</p></div>
      <div class="card feature"><div class="ico"><?= icon('chart') ?></div><h3>Чистая прибыль</h3><p>Оценка прибыли по выкупам в ленте и точный расчёт по отчётам реализации: ОПИУ, товары, ABC-анализ.</p></div>
      <div class="card feature"><div class="ico"><?= icon('send') ?></div><h3>Уведомления в MAX и Telegram</h3><p>Новый заказ — сразу сообщение с товаром, ценой, складом и городом. Можно включить выкупы, отмены и возвраты.</p></div>
      <div class="card feature"><div class="ico"><?= icon('store') ?></div><h3>Магазины и валюты</h3><p>Подключите несколько кабинетов WB — российских, киргизских, казахстанских. Суммы показываются в валюте кабинета.</p></div>
      <div class="card feature"><div class="ico"><?= icon('users') ?></div><h3>Команда</h3><p>Добавьте менеджеров с отдельным входом: они видят ленту, но не тариф и оплату.</p></div>
      <div class="card feature"><div class="ico"><?= icon('download') ?></div><h3>Выгрузка в Excel</h3><p>Заказы за любой период, прибыль по товарам и детализация отчётов — в файл для Excel в один клик.</p></div>
      <div class="card feature"><div class="ico"><?= icon('lock') ?></div><h3>Безопасно</h3><p>Токен WB хранится в зашифрованном виде (AES-256) и работает только на чтение. Удалить его можно в один клик.</p></div>
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

<section class="section" id="how">
  <div class="container">
    <h2>Подключение за 3 минуты</h2>
    <p class="sub">Никаких программ и настроек: нужен только токен из кабинета продавца.</p>
    <div class="grid grid-3 steps">
      <div class="step"><h3>Зарегистрируйтесь</h3><p class="muted">Логин, телефон и пароль — пробный период на <?= $trial ?> дней включится автоматически.</p></div>
      <div class="step"><h3>Создайте токен WB</h3><p class="muted">Кабинет продавца → Настройки → Доступ к API → тип «Персональный». Отметьте «Аналитика», «Статистика», «Маркетплейс», «Контент», «Финансы», «Продвижение» и «Только чтение».</p></div>
      <div class="step"><h3>Смотрите ленту и прибыль</h3><p class="muted">Вставьте токен — заказы появятся через несколько минут, отчёты реализации загрузятся сами, а уведомления придут в Telegram.</p></div>
    </div>
  </div>
</section>

<section class="section" id="pricing" style="padding-top:0">
  <div class="container">
    <h2>Тарифы</h2>
    <p class="sub">Начните с бесплатного пробного периода. Скидка до 20% при оплате за несколько месяцев.</p>
    <div class="plans">
      <?php foreach ($plans as $code => $p): ?>
        <div class="card plan <?= !empty($p['popular']) ? 'popular' : '' ?>">
          <?php if (!empty($p['popular'])): ?><span class="badge badge-accent tag">Популярный</span><?php endif; ?>
          <div class="plan-name"><?= e($p['name']) ?></div>
          <?php if (!empty($p['tagline'])): ?><div class="plan-tagline"><?= e($p['tagline']) ?></div><?php endif; ?>
          <div class="plan-price"><?= $p['price'] > 0 ? number_format($p['price'], 0, ',', ' ') . ' ₽' : '0 ₽' ?> <small>/ мес</small></div>
          <ul>
            <?php foreach ($p['features'] as $feat): ?><li><?= e($feat) ?></li><?php endforeach; ?>
          </ul>
          <a class="btn <?= !empty($p['popular']) ? '' : 'btn-ghost' ?> btn-block" href="/register"><?= $p['price'] > 0 ? 'Попробовать' : 'Начать' ?></a>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted small" style="text-align:center;margin-top:18px">Можно вернуть оставшуюся сумму в течение 15 дней с даты оплаты — см. <a href="/oferta">оферту</a>.</p>
  </div>
</section>

<section class="section" id="faq">
  <div class="container" style="max-width:820px">
    <h2>Частые вопросы</h2>
    <div class="faq" style="margin-top:28px">
      <details><summary>Откуда берутся данные?</summary><p>Только из официального API Wildberries по вашему токену: «Лента заказов» (реальное время), «Статистика» (продажи и возвраты), сборочные задания «Маркетплейса», карточки «Контента», отчёты реализации «Финансов» и расходы «Продвижения».</p></details>
      <details><summary>Совпадают ли цифры с кабинетом WB?</summary><p>Количество заказов и выкупов совпадает: заказы считаются по ленте WB без отменённых, выкупы — по дате выкупа за вычетом возвратов. Суммы могут отличаться на 2–5%: для кабинетов не в рублях WB сам пересчитывает цены по своему курсу. Точные деньги — в разделе «Финансы» по отчётам реализации.</p></details>
      <details><summary>Почему прибыль в ленте указана «по выкупам»?</summary><p>Деньги приходят только за выкупленные товары, поэтому главная цифра — прибыль по выкупам за период. Рядом показана оценка по всем заказам. В ленте это оценка по тарифам WB, точный расчёт — в «Финансах».</p></details>
      <details><summary>Работает ли с кабинетами в сомах и тенге?</summary><p>Да. Сервис определяет валюту каждого кабинета и показывает суммы в ней. Отчёты в разных валютах считаются отдельно, без смешивания.</p></details>
      <details><summary>Это безопасно? Сервис может что-то изменить в кабинете?</summary><p>Создайте токен в режиме «Только чтение» — тогда через него невозможно ничего изменить. Токен хранится в зашифрованном виде, его можно удалить в любой момент.</p></details>
      <details><summary>Какой тип токена выбрать?</summary><p>«Персональный». С «Базовым» токеном WB разрешает запрашивать ленту только раз в 3 часа и не даёт доступа к отчётам реализации.</p></details>
      <details><summary>Как оплатить?</summary><p>Банковской картой или СБП через ЮKassa прямо в личном кабинете. Тариф включается сразу после оплаты.</p></details>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="container" style="text-align:center">
    <div class="card" style="padding:40px 20px;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;border:0">
      <h2 style="color:#fff">Попробуйте бесплатно <?= $trial ?> дней</h2>
      <p style="opacity:.9;margin-bottom:22px">Подключите магазин за 3 минуты и посмотрите на свои заказы и прибыль по-новому.</p>
      <a class="btn btn-lg" style="background:#fff;color:#4c1d95" href="/register">Создать аккаунт</a>
      <?php if (support_telegram() !== ''): ?><p style="margin:18px 0 0;opacity:.9">Новости и поддержка — в Telegram-канале <a href="https://t.me/<?= e(support_telegram()) ?>" target="_blank" rel="noopener" style="color:#fff;font-weight:700">@<?= e(support_telegram()) ?></a></p><?php endif; ?>
    </div>
  </div>
</section>
