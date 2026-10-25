<!doctype html>
<html lang="ru">
<head>
<?= App\Core\View::partial('partials/head', ['title' => $title ?? '']) ?>
<meta name="robots" content="noindex">
</head>
<body>
<div class="auth-wrap">
  <?= $content ?>
</div>
<script src="<?= asset('js/forms.js') ?>"></script>
</body>
</html>
