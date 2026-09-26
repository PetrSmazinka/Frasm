<?php

/**
 * @file 404.php
 * @brief Production "not found" page (used when app.debug is false).
 * @var int $status
 * @var string $message
 */
$pageTitle = 'Page not found';
require FRASM_APP_DIR . '/Views/layout/header.php';
?>
<h1>404 – <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></h1>
<p>The page you are looking for does not exist. <a href="/">Back home</a></p>
<?php require FRASM_APP_DIR . '/Views/layout/footer.php'; ?>
