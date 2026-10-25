<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(($title ?? '') !== '' ? $title . ' — ' . config('app.name') : config('app.name')) ?></title>
<meta name="description" content="Лента заказов Wildberries в реальном времени: FBO, FBS, DBS, DBW и самовывоз в одном окне, уведомления в Telegram, выгрузка в Excel.">
<meta name="theme-color" content="#7c3aed">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<script>
  try { var t = localStorage.getItem('lz-theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}
</script>
