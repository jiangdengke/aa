<?php
if (!function_exists('app_admin_is_authenticated')) {
    require __DIR__ . '/app.php';
    app_start_session();
}

if (!app_admin_is_authenticated()) {
    require APP_BASE_DIR . '/admin-login.php';
    return;
}
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>管理后台</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_asset_url('public/styles.css'), ENT_QUOTES, 'UTF-8') ?>" />
  </head>
  <body>
    <div class="void-glow glow-cyan"></div>
    <div class="void-glow glow-magenta"></div>
    <div class="rain"></div>
    <div class="scanlines"></div>

    <main class="shell shell-admin">
      <section class="card admin-card">
        <p class="eyebrow">dogcoding</p>
        <div class="admin-title-row">
          <div>
            <h1>管理后台</h1>
            <p class="lede">已通过访问密钥验证，可添加用户余额、查看操作日志和管理首页二维码。</p>
          </div>
          <button id="admin-logout" class="mini-button" type="button">退出登录</button>
        </div>

        <div class="admin-tabs" role="tablist" aria-label="管理功能">
          <button class="admin-tab is-active" type="button" data-tab-target="balance-panel">添加余额</button>
          <button class="admin-tab" type="button" data-tab-target="gallery-panel">管理图片</button>
        </div>

        <section id="balance-panel" class="admin-panel is-active">
          <section class="admin-section">
            <div class="section-head">
              <h2 class="section-title">添加用户余额</h2>
              <p class="section-desc">输入用户邮箱和金额，系统会核验账户后添加余额，并写入下方记录。</p>
            </div>

            <form id="balance-form" class="claim-form admin-auth-form">
              <div class="admin-filters admin-filters-three">
                <label class="field">
                  <span class="field-label">用户邮箱</span>
                  <div class="input-shell">
                    <input id="balance-email" type="email" placeholder="user@example.com" required />
                  </div>
                </label>

                <label class="field">
                  <span class="field-label">添加金额</span>
                  <div class="input-shell">
                    <input id="balance-amount" type="number" min="0.01" step="0.01" placeholder="10" required />
                  </div>
                </label>

                <label class="field">
                  <span class="field-label">备注</span>
                  <div class="input-shell">
                    <input id="balance-notes" type="text" placeholder="管理员手动加余额" />
                  </div>
                </label>
              </div>

              <button id="balance-submit" class="submit-button" type="submit">
                <span class="submit-button__label">添加余额</span>
                <span class="submit-button__value">手动操作</span>
              </button>
            </form>

            <section id="balance-status" class="status-card status-idle" aria-live="polite">
              <p class="status-eyebrow">操作状态</p>
              <p id="balance-status-title" class="status-title">等待操作</p>
              <p id="balance-status-message" class="status-message">添加成功后会自动刷新领取记录。</p>
            </section>
          </section>

          <section class="admin-section">
            <div class="section-head">
              <h2 class="section-title">余额记录</h2>
              <p class="section-desc">按邮箱、状态和排序条件查询自助领取与手动加余额记录。</p>
            </div>

            <form id="admin-form" class="claim-form admin-auth-form">
              <div class="admin-filters">
                <label class="field">
                  <span class="field-label">搜索邮箱</span>
                  <div class="input-shell">
                    <input
                      id="search-email"
                      name="search-email"
                      type="text"
                      autocomplete="off"
                      placeholder="按邮箱筛选"
                    />
                  </div>
                </label>

                <label class="field">
                  <span class="field-label">状态</span>
                  <div class="input-shell">
                    <select id="status-filter" class="admin-select">
                      <option value="">全部</option>
                      <option value="completed">已成功</option>
                      <option value="pending">处理中</option>
                      <option value="failed">失败</option>
                    </select>
                  </div>
                </label>
              </div>

              <div class="admin-filters">
                <label class="field">
                  <span class="field-label">排序字段</span>
                  <div class="input-shell">
                    <select id="sort-by" class="admin-select">
                      <option value="awardedAt">领取时间</option>
                      <option value="createdAt">创建时间</option>
                      <option value="email">邮箱</option>
                      <option value="type">类型</option>
                      <option value="status">状态</option>
                      <option value="amount">金额</option>
                    </select>
                  </div>
                </label>

                <label class="field">
                  <span class="field-label">排序方式</span>
                  <div class="input-shell">
                    <select id="sort-order" class="admin-select">
                      <option value="desc">降序</option>
                      <option value="asc">升序</option>
                    </select>
                  </div>
                </label>
              </div>

              <button id="admin-submit" class="submit-button" type="submit">
                <span class="submit-button__label">查询记录</span>
                <span class="submit-button__value" id="records-total">0 条</span>
              </button>
            </form>

            <section id="admin-status" class="status-card status-idle" aria-live="polite">
              <p class="status-eyebrow">查询状态</p>
              <p id="admin-status-title" class="status-title">等待查询</p>
              <p id="admin-status-message" class="status-message">登录后即可查看记录。</p>
            </section>

            <div class="records-table-wrap">
              <table class="records-table">
                <thead>
                  <tr>
                    <th>邮箱</th>
                    <th>类型</th>
                    <th>金额</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th>领取时间</th>
                    <th>备注</th>
                  </tr>
                </thead>
                <tbody id="records-body">
                  <tr>
                    <td colspan="7" class="records-empty">暂无数据</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="admin-section">
            <div class="section-head section-head-row">
              <div>
                <h2 class="section-title">操作日志</h2>
                <p class="section-desc">查看最近的余额发放、手动加余额、二维码管理和失败原因。</p>
              </div>
              <button id="logs-refresh" class="mini-button" type="button">刷新日志</button>
            </div>

            <div class="records-table-wrap">
              <table class="records-table logs-table">
                <thead>
                  <tr>
                    <th>时间</th>
                    <th>级别</th>
                    <th>动作</th>
                    <th>邮箱</th>
                    <th>用户 ID</th>
                    <th>金额</th>
                    <th>详情</th>
                  </tr>
                </thead>
                <tbody id="logs-body">
                  <tr>
                    <td colspan="7" class="records-empty">暂无日志</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </section>

        <section id="gallery-panel" class="admin-panel" hidden>
          <div class="section-head">
            <h2 class="section-title">二维码图片管理</h2>
            <p class="section-desc">上传后首页会自动并排显示，支持删除和上下调整顺序。</p>
          </div>

          <form id="upload-form" class="claim-form admin-auth-form">
            <div class="admin-filters">
              <label class="field">
                <span class="field-label">图片标题</span>
                <div class="input-shell">
                  <input id="image-title" type="text" placeholder="例如：微信群一群" />
                </div>
              </label>

              <label class="field">
                <span class="field-label">图片说明</span>
                <div class="input-shell">
                  <input id="image-alt" type="text" placeholder="例如：扫码进群" />
                </div>
              </label>
            </div>

            <label class="field">
              <span class="field-label">上传图片</span>
              <div class="input-shell">
                <input id="image-file" type="file" accept="image/*" required />
              </div>
            </label>

            <button id="upload-submit" class="submit-button" type="submit">
              <span class="submit-button__label">上传图片</span>
              <span class="submit-button__value">更新首页</span>
            </button>
          </form>

          <section id="gallery-status" class="status-card status-idle" aria-live="polite">
            <p class="status-eyebrow">图片状态</p>
            <p id="gallery-status-title" class="status-title">等待操作</p>
            <p id="gallery-status-message" class="status-message">上传、删除或调整顺序后会立即生效。</p>
          </section>

          <div id="gallery-admin-grid" class="gallery-admin-grid"></div>
        </section>
      </section>
    </main>

    <script src="<?= htmlspecialchars(app_asset_url('public/admin.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
  </body>
</html>
