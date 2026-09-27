<?php

/**
 * @file about.php
 * @brief Second demo page.
 *
 * @var string $phpVersion
 */

require FRASM_APP_DIR . '/Views/layout/header.php';
?>
<h1>About</h1>
<p class="lead">A lightweight PHP framework for Apache and MariaDB, at home on a Raspberry Pi.</p>

<div class="card">
    <p>This application runs on PHP <?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?>.</p>
    <p class="muted">Your code lives in <code>app/</code>; the framework in <code>core/</code> is replaced
        by <code>make update</code> and must not be edited.</p>
</div>
<?php require FRASM_APP_DIR . '/Views/layout/footer.php'; ?>
