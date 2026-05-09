<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>dogcoding 额度领取</title>
    <meta
      name="description"
      content="输入注册邮箱，自助领取一次 10 刀额度。"
    />
    <link rel="stylesheet" href="<?= htmlspecialchars(app_asset_url('public/styles.css'), ENT_QUOTES, 'UTF-8') ?>" />
  </head>
  <body>
    <div class="void-glow glow-cyan"></div>
    <div class="void-glow glow-magenta"></div>
    <div class="rain"></div>
    <div class="scanlines"></div>

    <main class="shell">
      <section class="card">
        <p class="eyebrow">dogcoding</p>
        <h1>邮箱领取额度</h1>
        <p class="lede">输入已注册邮箱，系统会自动核验并发放一次性奖励。</p>

        <section class="notice-card notice-card-top">
          <div class="notice-copy">
            <p class="status-eyebrow">最新公告</p>
            <h2 class="notice-title">近期被恶意刷注册，现已暂停在此领取额度</h2>
            <p class="notice-text">
              如需了解后续开放时间、活动通知和不定时抽奖，可以扫码进群。
            </p>
            <p class="notice-text">
              群内会同步最新消息，后续恢复领取也会优先在群里通知。
            </p>
          </div>
        </section>

        <?php $galleryItems = app_list_gallery(); ?>
        <section class="gallery-card">
          <div class="gallery-head">
            <p class="status-eyebrow">群二维码</p>
            <p class="gallery-hint">扫码进群，参与不定时抽奖。</p>
          </div>
          <div class="gallery-strip">
            <?php if ($galleryItems === []): ?>
              <div class="gallery-empty">暂无二维码，请到管理后台上传。</div>
            <?php else: ?>
              <?php foreach ($galleryItems as $item): ?>
                <figure class="gallery-item">
                  <img
                    src="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"
                    alt="<?= htmlspecialchars((string) ($item['alt'] ?? $item['title'] ?? '二维码'), ENT_QUOTES, 'UTF-8') ?>"
                  />
                  <figcaption>
                    <a
                      href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"
                      target="_blank"
                      rel="noreferrer"
                    ><?= htmlspecialchars((string) ($item['title'] ?? '二维码'), ENT_QUOTES, 'UTF-8') ?></a>
                  </figcaption>
                </figure>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      </section>
    </main>

    <script src="<?= htmlspecialchars(app_asset_url('public/app.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
  </body>
</html>
