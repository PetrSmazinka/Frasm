<?php

/**
 * @file header.php
 * @brief Shared page header: include at the top of every full-page view.
 *
 * @var string|null $pageTitle
 * @var string $csrf_field
 * @var string|null $flash_success
 * @var string|null $flash_error
 * @var string|null $flash_info
 */

use Core\Auth\Auth;
use Core\Container\Container;
use Core\Http\Request;

$currentPath = Container::getInstance()->get(Request::class)->path();
$navLink = static function (string $path, string $label) use ($currentPath): string {
    $current = $currentPath === $path ? ' aria-current="page"' : '';
    return '<a href="' . $path . '"' . $current . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(($pageTitle ?? 'Home') . ' · Frasm', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(FRASM_ROOT_DIR . '/public/css/app.css') ?>" data-frasm-track>
    <?= frasm_head() ?>
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="/">Frasm</a>
        <nav class="nav">
            <?= $navLink('/', 'Home') ?>
            <?= $navLink('/about', 'About') ?>
            <?php if (Auth::check()): ?>
                <form method="post" action="/logout">
                    <?= $csrf_field ?? \Core\Security\Csrf::field() ?>
                    <button type="submit" class="btn btn--link">Sign out</button>
                </form>
            <?php else: ?>
                <?= $navLink('/login', 'Sign in') ?>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="site-main">
<?php foreach (['success' => $flash_success ?? null, 'error' => $flash_error ?? null, 'info' => $flash_info ?? null] as $type => $message): ?>
    <?php if (!empty($message)): ?>
        <div class="alert alert--<?= $type ?>" role="status"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
<?php endforeach; ?>
