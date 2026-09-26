<?php

/**
 * @file login.php
 * @var string|null $pageTitle
 * @var string|null $flash_error
 * @var string|null $flash_info
 * @var string $csrf_field
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Sign In', ENT_QUOTES, 'UTF-8') ?></title>
    <?= frasm_head() ?>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-card {
            background: #1e293b;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            width: 100%;
            max-width: 380px;
        }
        h1 { 
            margin-top: 0; 
            font-size: 1.5rem; 
            text-align: center; 
        }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
        }
        .alert-error { 
            background: rgba(239, 68, 68, 0.15); 
            border: 1px solid #ef4444; 
            color: #fca5a5; 
        }
        .alert-info { 
            background: rgba(59, 130, 246, 0.15); 
            border: 1px solid #3b82f6; 
            color: #93c5fd; 
        }
        .form-group { 
            margin-bottom: 1.25rem; 
        }
        label { 
            display: block; 
            margin-bottom: 0.4rem; 
            font-size: 0.85rem; 
            color: #94a3b8; 
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 0.65rem 0.75rem;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #fff;
            box-sizing: border-box;
            font-size: 1rem;
        }
        input:focus { 
            border-color: #6366f1; 
            outline: none; 
        }
        button {
            width: 100%;
            padding: 0.75rem;
            background: #6366f1;
            border: none;
            border-radius: 6px;
            color: #fff;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover { 
            background: #4f46e5; 
        }
    </style>
</head>
<body>

<div class="login-card">
    <h1>Sign In</h1>

    <!-- Flash message notifications -->
    <?php if (!empty($flash_error)): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($flash_info)): ?>
        <div class="alert alert-info">
            <?= htmlspecialchars($flash_info, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/login">
        <!-- Injected CSRF protection token -->
        <?= $csrf_field ?>

        <div class="form-group">
            <label for="identifier">Username or Email</label>
            <input type="text" id="identifier" name="identifier" required autofocus>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem;">
            <input type="checkbox" id="remember_me" name="remember_me" value="1" style="width: auto; cursor: pointer;">
            <label for="remember_me" style="margin-bottom: 0; cursor: pointer; color: #cbd5e1; font-size: 0.9rem;">
                Remember me
            </label>
        </div>

        <button type="submit">Sign In</button>
    </form>
</div>

</body>
</html>