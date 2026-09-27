<?php

/**
 * @file index.php
 * @brief Welcome page.
 *
 * @var string $counter Rendered live component.
 */

require FRASM_APP_DIR . '/Views/layout/header.php';
?>
<h1>Hello, Frasm!</h1>
<p class="lead">Your application is up and running.</p>

<h2>Live component</h2>
<p class="muted">The counter keeps its state on the server. Clicking a button sends a small request and
    re-renders only this component; no JavaScript was written for it.</p>
<div class="card">
    <?= $counter ?>
</div>

<h2>Where to go next</h2>
<ul>
    <li>Controllers live in <code>app/Controllers</code>, views in <code>app/Views</code>.</li>
    <li>Open the <a href="/about">About page</a>: navigation swaps the page without a full reload.</li>
    <li>Run <code>make help</code> to see the command-line tools.</li>
</ul>
<?php require FRASM_APP_DIR . '/Views/layout/footer.php'; ?>
