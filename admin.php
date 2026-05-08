<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>管理后台</title>
    <link rel="stylesheet" href="/public/styles.css" />
  </head>
  <body>
    <div class="void-glow glow-cyan"></div>
    <div class="void-glow glow-magenta"></div>
    <div class="rain"></div>
    <div class="scanlines"></div>

    <main class="shell shell-admin">
      <section class="card admin-card">
        <p class="eyebrow">dogcoding</p>
        <h1>管理后台</h1>
        <p class="lede">输入访问密钥后可查看领取记录、上传二维码图片、删除图片和调整首页展示顺序。</p>

        <form id="admin-form" class="claim-form admin-auth-form">
          <label class="field">
            <span class="field-label">访问密钥</span>
            <div class="input-shell">
              <input
                id="access-key"
                name="access-key"
                type="password"
                autocomplete="off"
                placeholder="输入访问密钥"
                required
              />
            </div>
          </label>

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
          <p id="admin-status-message" class="status-message">输入访问密钥后即可查看记录。</p>
        </section>

        <div class="records-table-wrap">
          <table class="records-table">
            <thead>
              <tr>
                <th>邮箱</th>
                <th>金额</th>
                <th>状态</th>
                <th>创建时间</th>
                <th>领取时间</th>
              </tr>
            </thead>
            <tbody id="records-body">
              <tr>
                <td colspan="5" class="records-empty">暂无数据</td>
              </tr>
            </tbody>
          </table>
        </div>

        <section class="admin-section">
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

    <script src="/public/admin.js" defer></script>
  </body>
</html>
