<?php $assetBase = ($base ?? '') !== '' ? $base : ''; ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tagom CRM - Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $assetBase ?>/assets/app.css" />
</head>
<body class="login-page">
  <div class="login-layout">
    <aside class="login-showcase">
      <div class="login-badge"> CRM</div>
      <h1 class="login-showcase-title">Internal Operations Dashboard</h1>
      <p class="login-showcase-text">
        Manage customers, suppliers, invoices, and collections from one secure control center.
      </p>
      <ul class="login-points">
        <li>Fast daily workflow</li>
        <li>Secure role-based access</li>
        <li>Clear finance visibility</li>
      </ul>
    </aside>

    <section class="login-card">
      <div class="login-head">
        <div class="login-kicker">Welcome back</div>
        <h2>Sign in to your account</h2>
        <p>Use your assigned credentials to continue.</p>
      </div>

      <?php if ($error): ?>
        <div class="login-err"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" action="./login.php" autocomplete="off" class="login-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <label class="login-label" for="username">Username</label>
        <div class="login-field">
          <span class="login-field-icon">U</span>
          <input id="username" class="login-input" name="username" value="<?= htmlspecialchars($username ?? '') ?>" required>
        </div>

        <label class="login-label" for="password">Password</label>
        <div class="login-field">
          <span class="login-field-icon">P</span>
          <input id="password" class="login-input" type="password" name="password" required>
        </div>

        <button class="login-btn" type="submit">Login</button>
      </form>

      <div class="login-muted">Tagom CRM - Internal System</div>
    </section>
  </div>
</body>
</html>
