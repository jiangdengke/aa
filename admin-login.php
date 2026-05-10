<?php
if (!function_exists('app_admin_is_authenticated')) {
    require __DIR__ . '/app.php';
    app_start_session();
}

if (app_admin_is_authenticated()) {
    header('Location: /admin.html');
    exit;
}
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>后台验证</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_asset_url('public/styles.css'), ENT_QUOTES, 'UTF-8') ?>" />
  </head>
  <body>
    <div class="void-glow glow-cyan"></div>
    <div class="void-glow glow-magenta"></div>
    <div class="rain"></div>
    <div class="scanlines"></div>

    <main class="shell">
      <section class="card admin-login-card">
        <p class="eyebrow">dogcoding</p>
        <h1>后台验证</h1>
        <p class="lede">请输入后台访问密钥，验证通过后才能进入管理后台。</p>

        <form id="admin-login-form" class="claim-form">
          <label class="field">
            <span class="field-label">访问密钥</span>
            <div class="input-shell">
              <input
                id="admin-login-key"
                name="accessKey"
                type="password"
                autocomplete="current-password"
                placeholder="输入 .env 里的 RECORDS_ACCESS_KEY"
                required
              />
            </div>
          </label>

          <button id="admin-login-submit" class="submit-button" type="submit">
            <span class="submit-button__label">进入后台</span>
            <span class="submit-button__value">Session 验证</span>
          </button>
        </form>

        <section id="admin-login-status" class="status-card status-idle" aria-live="polite">
          <p class="status-eyebrow">验证状态</p>
          <p id="admin-login-status-title" class="status-title">等待输入</p>
          <p id="admin-login-status-message" class="status-message">密钥只会发送到服务端校验，不会显示在页面中。</p>
        </section>
      </section>
    </main>

    <script src="<?= htmlspecialchars(app_asset_url('public/admin-login.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
  </body>
</html>
