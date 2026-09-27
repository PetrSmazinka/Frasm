<?php

/**
 * @file login.php
 * @brief Sign-in form.
 *
 * @var string $csrf_field
 * @var array<string, list<string>> $errors Validation errors.
 * @var array<string, mixed> $old Previously submitted input.
 */

require FRASM_APP_DIR . '/Views/layout/header.php';

$fieldError = static function (string $field) use ($errors): string {
    return isset($errors[$field][0])
        ? '<p class="field__error">' . htmlspecialchars($errors[$field][0], ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
};
?>
<div class="card card--narrow">
    <h1>Sign in</h1>
    <p class="muted">Use the administrator account created by <code>php bin/frasm db:seed</code>.</p>

    <form method="post" action="/login">
        <?= $csrf_field ?>

        <div class="field">
            <label for="identifier">Username or email</label>
            <input type="text" id="identifier" name="identifier" autocomplete="username" autofocus
                   value="<?= htmlspecialchars((string)($old['identifier'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <?= $fieldError('identifier') ?>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password">
            <?= $fieldError('password') ?>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="remember_me" value="1"> Keep me signed in
        </label>

        <button type="submit" class="btn btn--primary btn--block">Sign in</button>
    </form>
</div>
<?php require FRASM_APP_DIR . '/Views/layout/footer.php'; ?>
