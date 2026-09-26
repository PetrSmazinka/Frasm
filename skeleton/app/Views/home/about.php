<?php

/**
 * @file about.php
 * @brief Second demo page.
 * @var string $pageTitle
 * @var string $phpVersion
 */
require FRASM_APP_DIR . '/Views/layout/header.php';
?>
<h1>About</h1>
<p>Running on PHP <?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?>.</p>
<p>Your application code lives in <code>app/</code>; the framework in <code>core/</code> is replaced on updates.</p>
<?php require FRASM_APP_DIR . '/Views/layout/footer.php'; ?>
