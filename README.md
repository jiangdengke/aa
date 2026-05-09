# dogcoding 自助领 10 刀

这是一个 PHP 单体应用：

- 用户在首页输入邮箱领取额度
- 服务端登录 `https://ai.laodog.top` 管理后台
- 查询用户是否存在
- 给符合条件的用户加 `10`
- 每个账户只能领取一次
- 带服务端验证码
- 带管理后台、领取记录、二维码图库管理

## 文件结构

- `app.php`: PHP 核心逻辑
- `router.php`: PHP 内置服务器路由入口
- `index.php`: 首页
- `admin.php`: 管理后台页
- `public/`: 前端静态资源
- `data/claims.json`: 领取记录
- `data/gallery.json`: 二维码图库元数据
- `data/gallery/`: 二维码图片目录
- `data/locks/`: 文件锁目录

## 配置

先准备环境变量：

- `BASE_URL`: 你的站点地址，默认 `https://ai.laodog.top`
- `ADMIN_EMAIL`: 管理员邮箱
- `ADMIN_PASSWORD`: 管理员密码
- `RECORDS_ACCESS_KEY`: 查看领取记录的访问密钥
- `DB_DRIVER`: 存储驱动，默认 `file`；配置为 `mysql` 后使用数据库
- `DB_HOST`: MySQL 地址
- `DB_PORT`: MySQL 端口，默认 `3306`
- `DB_DATABASE`: MySQL 数据库名
- `DB_USERNAME`: MySQL 用户名
- `DB_PASSWORD`: MySQL 密码
- `DB_CHARSET`: MySQL 字符集，默认 `utf8mb4`
- `CLAIM_AMOUNT`: 赠送额度，默认 `10`
- `CLAIM_NOTES`: 写入后台余额记录的备注
- `RATE_LIMIT_MAX`: 单个 IP 10 分钟内最大请求次数，默认 `20`
- `CAPTCHA_TTL_SECONDS`: 验证码有效期，默认 `300`
- `CAPTCHA_MAX_ATTEMPTS`: 单个验证码最多尝试次数，默认 `5`
- `CAPTCHA_RATE_LIMIT_MAX`: 单个 IP 10 分钟内最多刷新验证码次数，默认 `40`
- `GALLERY_UPLOAD_MAX_FILES`: 首页最多保留的图片数量，默认 `8`
- `GALLERY_UPLOAD_MAX_BYTES`: 单张上传图片最大体积，默认 `3145728`

## 本地启动

需要 PHP 8.2+，直接用 PHP 内置服务器：

```bash
php -S 0.0.0.0:3000 router.php
```

启动后访问：

```text
http://127.0.0.1:3000
```

领取记录页：

```text
http://127.0.0.1:3000/admin.php
```

管理后台页：

```text
http://127.0.0.1:3000/admin.php
```

## Docker Compose 启动

推荐直接用 Compose：

```bash
docker compose up -d --build
```

查看日志：

```bash
docker compose logs -f
```

停止服务：

```bash
docker compose down
```

如果你改了 `.env`，重新启动：

```bash
docker compose up -d --build
```

Compose 配置文件在 [docker-compose.yml](/home/jdk/code/aa/docker-compose.yml)，默认会：

- 读取当前目录的 `.env`
- 把本地 `./data` 挂载到容器内 `/app/data`
- 暴露端口 `3000`
- 使用 `unless-stopped` 自动重启策略

## 数据持久化

默认不配置数据库时，应用继续使用文件存储：

- `data/claims.json`: 领取记录和手动加余额记录
- `data/gallery.json`: 二维码图库元数据
- `data/gallery/`: 二维码图片文件
- `data/rate_limits.json`: 限流数据

如果 `.env` 配置了 MySQL，领取记录、二维码元数据和限流数据会写入数据库。二维码图片文件仍保存在 `data/gallery/`，所以 Compose 里的 `./data:/app/data` 仍然需要保留。

MySQL 示例：

```env
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laodog_bonus
DB_USERNAME=laodog_bonus
DB_PASSWORD=replace-me
DB_CHARSET=utf8mb4
```

首次启用数据库时，如果数据库表为空，应用会自动把现有 `data/claims.json` 和 `data/gallery.json` 导入数据库。

## 接口逻辑

当前实现直接调用了站点已有接口：

- `POST /api/v1/auth/login`
- `GET /api/v1/admin/users?search=邮箱`
- `POST /api/v1/admin/users/:id/balance`

余额接口使用：

- `operation: "add"`
- `balance: 10`

应用自身还提供：

- `GET /api/config`
- `GET /api/captcha`
- `POST /api/claim`
- `GET /api/admin/claims`
- `POST /api/admin/balance`
- `GET /api/gallery`
- `POST /api/gallery/upload`
- `DELETE /api/gallery/:id`
- `POST /api/gallery/:id/move`
- `GET /media/:id`

## 已做保护

- 管理员邮箱和密码只保存在服务端环境变量里，不会发到前端
- 同一邮箱和同一用户 ID 都会拦截重复领取
- 使用本地 `claims.json` 做持久化
- 配置 MySQL 后会使用数据库持久化，自动领取查重有数据库唯一键兜底
- 领取前先写入 `pending` 状态，避免并发重复发放
- 有基础 IP 限流
- 已接入服务端验证码，领取时必须输入正确验证码
- 验证码带有效期、尝试次数限制和刷新频率限制
- 已接入领取记录查询接口，必须提供 `RECORDS_ACCESS_KEY` 才能读取
- 管理后台可按邮箱给用户手动添加自定义余额，操作记录会写入 `data/claims.json`
- 已接入二维码图片上传接口，使用同一个 `RECORDS_ACCESS_KEY` 管理
- 管理后台已支持查看记录、上传二维码、删除二维码、调整图片顺序

## 需要你知道的风险

如果页面只让用户输入邮箱就能领取，那么任何知道别人邮箱的人都可以替对方触发领取，且还能用返回消息判断这个邮箱是否存在。

如果你要正式上线，建议下一步至少补一个校验：

- 邮箱验证码
- 登录态校验
- 邀请码
- Cloudflare Turnstile / CAPTCHA

如果你要，我可以下一步直接把“邮箱验证码后才能领”的版本也接上。
