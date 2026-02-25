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
  <div class="login-card">
    <h2>Login page</h2>

    <?php if ($error): ?>
      <div class="login-err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="./login.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <label class="login-label">Username</label>
      <input class="login-input" name="username" required>
      <label class="login-label">Password</label>
      <input class="login-input" type="password" name="password" required>
      <button class="login-btn" type="submit">Login</button>
    </form>

    <div class="login-muted">Tagom CRM - Internal System</div>
  </div>
</body>
</html>
