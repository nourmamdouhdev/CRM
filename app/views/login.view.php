<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tagom CRM - Login</title>
  <style>
    body{font-family:Arial;margin:0;background:#f6f7fb}
    .box{max-width:420px;margin:9vh auto;background:#fff;padding:22px;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
    label{display:block;margin:10px 0 6px}
    input{width:100%;padding:12px;border:1px solid #ddd;border-radius:10px}
    button{width:100%;padding:12px;border:0;border-radius:10px;margin-top:14px;cursor:pointer}
    .err{background:#ffecec;border:1px solid #ffb3b3;padding:10px;border-radius:10px;margin:10px 0;color:#b00020}
    .muted{color:#666;font-size:13px;margin-top:10px}
  </style>
</head>
<body>
  <div class="box">
    <h2>تسجيل الدخول</h2>

    <?php if ($error): ?>
      <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="./login.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <label>Username</label>
      <input name="username" required>
      <label>Password</label>
      <input type="password" name="password" required>
      <button type="submit">دخول</button>
    </form>

    <div class="muted">Tagom CRM - Internal System</div>
  </div>
</body>
</html>
