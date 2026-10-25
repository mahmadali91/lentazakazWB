<!doctype html>
<html lang="ru">
<head>
<?= App\Core\View::partial('partials/head', ['title' => $title ?? '']) ?>
</head>
<body>
<header class="site-header">
  <div class="container">
    <?= App\Core\View::partial('partials/logo') ?>
    <nav class="site-nav">
      <a class="nav-link" href="/#features">Возможности</a>
      <a class="nav-link" href="/#pricing">Тарифы</a>
      <a class="nav-link" href="/#faq">Вопросы</a>
      <?php if (!empty($user)): ?>
        <a class="btn btn-sm" href="/app">Открыть ленту</a>
      <?php else: ?>
        <a class="nav-link" href="/login">Войти</a>
        <a class="btn btn-sm" href="/register">Попробовать бесплатно</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main>
  <?= $content ?>
</main>
<footer class="site-footer">
  <div class="container">
    <div>© <?= date('Y') ?> <?= e(config('app.name')) ?>. Сервис не является официальным продуктом Wildberries.</div>
    <div class="row">
      <a href="/oferta">Оферта</a>
      <a href="/privacy">Конфиденциальность</a>
      <?php if (config('app.support_email')): ?><a href="mailto:<?= e(config('app.support_email')) ?>">Поддержка</a><?php endif; ?>
    </div>
  </div>
</footer>
</body>
</html>
