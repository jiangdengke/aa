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
    <link rel="stylesheet" href="/public/styles.css" />
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

        <section class="gallery-card">
          <div class="gallery-head">
            <p class="status-eyebrow">群二维码</p>
            <p class="gallery-hint">扫码进群，参与不定时抽奖。</p>
          </div>
          <div id="gallery-strip" class="gallery-strip"></div>
        </section>
      </section>
    </main>

    <script src="/public/app.js" defer></script>
  </body>
</html>
