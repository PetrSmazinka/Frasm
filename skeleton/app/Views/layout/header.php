<?php

/**
 * @file header.php
 * @brief Shared page header: include at the top of full-page views.
 * @var string|null $pageTitle
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Frasm', ENT_QUOTES, 'UTF-8') ?></title>
    <?= frasm_head() ?>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 42rem; margin: 3rem auto; padding: 0 1rem; color: #1e293b; }
        nav a { margin-right: 1rem; }
        .counter { display: flex; gap: .75rem; align-items: center; padding: 1rem; border: 1px solid #cbd5e1; border-radius: 8px; }
    </style>
</head>
<body>
<nav>
    <a href="/">Home</a>
    <a href="/about">About</a>
    <a href="/login">Sign in</a>
</nav>
