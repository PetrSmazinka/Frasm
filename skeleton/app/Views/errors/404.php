<?php

/**
 * @file 404.php
 * @brief Production "not found" page (used when app.debug is false).
 *
 * @var int $status
 * @var string $message
 */

$pageTitle = 'Page not found';
require FRASM_APP_DIR . '/Views/layout/header.php';
?>
<div class="card card--narrow">
    <h1>404</h1>
    <p class="muted">The page you are looking for does not exist.</p>
    <a class="btn btn--primary" href="/">Back home</a>
</div>
<?php require FRASM_APP_DIR . '/Views/layout/footer.php'; ?>
