<?php

/**
 * @file index.php
 * @brief Welcome page.
 * @var string $pageTitle
 * @var string $counter Rendered live component.
 */
require FRASM_APP_DIR . '/Views/layout/header.php';
?>
<h1>Hello, Frasm! 👋</h1>
<p>This page is rendered by <code>App\Controllers\HomeController::index()</code>.</p>

<h2>Live component</h2>
<p>The counter below talks to the server without writing any JavaScript:</p>
<?= $counter ?>

<p>Continue to the <a href="/about">About page</a> – the transition happens without a full page reload.</p>
<?php require FRASM_APP_DIR . '/Views/layout/footer.php'; ?>
