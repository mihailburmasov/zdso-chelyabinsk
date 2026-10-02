<?php /** @var array $sess */ /** @var string $prefix */ ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Управление сайтом — Завод ДСО</title>
<link rel="stylesheet" href="<?= esc($prefix) ?>/asset/admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
</head>
<body>
<?= sprite_svg() ?>
<div class="app">
  <aside class="side" id="side">
    <a class="side__logo" href="#/"><?= LOGO_MARK ?><span><b>Завод ДСО</b><small>управление сайтом</small></span></a>
    <nav class="side__nav" id="nav" aria-label="Разделы"></nav>
    <div class="side__foot">
      <a class="side__site" href="<?= esc(S::$base) ?>/" target="_blank" rel="noopener">Открыть сайт ↗</a>
      <form method="post" action="<?= esc($prefix) ?>/logout"><button class="side__logout" type="submit">Выйти</button></form>
    </div>
  </aside>
  <div class="main">
    <header class="top">
      <button class="top__menu" type="button" id="menuBtn" aria-label="Меню"><?= adm_icon('menu') ?></button>
      <div class="top__title" id="pageTitle">Загрузка…</div>
      <div class="top__actions" id="topActions"></div>
    </header>
    <main class="content" id="view"></main>
  </div>
</div>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script>window.ADMIN = <?= json_encode(['api' => $prefix . '/api/', 'csrf' => $sess['csrf'], 'base' => S::$base], JSON_FLAGS) ?>;</script>
<script src="<?= esc($prefix) ?>/asset/admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>"></script>
</body>
</html>
