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
- `BIND_ADDRESS`: 容器内部监听地址，Docker Compose 默认覆盖为 `0.0.0.0`
- `UPSTREAM_BASE_URL`: 可选，后端调用上游接口的内部地址；为空时使用 `BASE_URL`
- `UPSTREAM_HOST_HEADER`: 可选，当 `UPSTREAM_BASE_URL` 配成 IP 或内网域名时，用它指定上游需要的 `Host`
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
- `LOG_FILE`: 应用日志文件，默认 `/var/www/html/data/app.log`
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

管理后台会先显示密钥验证页，输入 `.env` 里的 `RECORDS_ACCESS_KEY` 后才会进入后台。登录状态保存在服务端 PHP session，后台右上角可退出登录。

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
- 把本地 `./data` 挂载到容器内 `/var/www/html/data`
- 把宿主机 `127.0.0.1:3000` 映射到容器 `3000`，`docker ps` 会显示端口
- 使用 `unless-stopped` 自动重启策略

当前 Compose 使用端口映射模式，并只绑定宿主机本机地址：

```yaml
ports:
  - "127.0.0.1:${PORT:-3000}:${PORT:-3000}"
```

这样 `docker ps` 会显示 `127.0.0.1:3000->3000/tcp`，公网不会直接访问 Docker 端口，Nginx 可以这样反代：

```nginx
location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

如果后台添加余额再次出现 `无法连接上游服务`，说明 bridge 网络仍然访问不了 `https://ai.laodog.top`。优先把 `UPSTREAM_BASE_URL` 配成宿主机或内网可访问地址，例如 `http://host.docker.internal:上游端口`。

## 数据持久化

默认不配置数据库时，应用继续使用文件存储：

- `data/claims.json`: 领取记录和手动加余额记录
- `data/gallery.json`: 二维码图库元数据
- `data/gallery/`: 二维码图片文件
- `data/rate_limits.json`: 限流数据

如果 `.env` 配置了 MySQL，领取记录、二维码元数据和限流数据会写入数据库。二维码图片文件仍保存在 `data/gallery/`，所以 Compose 里的 `./data:/var/www/html/data` 仍然需要保留。

MySQL 示例：

```env
DB_DRIVER=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laodog_bonus
DB_USERNAME=laodog_bonus
DB_PASSWORD=replace-me
DB_CHARSET=utf8mb4
LOG_FILE=/var/www/html/data/app.log
```

如果后台“添加余额”返回 `无法连接上游服务`，说明本应用容器无法稳定访问 `BASE_URL` 的上游 API。部署在同一台服务器时，建议让它走内网或宿主机地址，例如：

```env
BASE_URL=https://ai.laodog.top
UPSTREAM_BASE_URL=http://host.docker.internal:你的上游端口
UPSTREAM_HOST_HEADER=ai.laodog.top
```

如果你的上游服务就在另一个 Compose 服务里，也可以把 `UPSTREAM_BASE_URL` 配成服务名，例如 `http://open-webui:8080`。关键是这个地址必须能从 `laodog-bonus-claim` 容器内部访问。

首次启用数据库时，如果数据库表为空，应用会自动把现有 `data/claims.json` 和 `data/gallery.json` 导入数据库。

容器启动时会自动执行一次数据库初始化。也可以手动执行：

```bash
docker compose exec laodog-bonus-claim php init-db.php
```

## 日志

应用日志会写入 `LOG_FILE`，默认是：

```text
/var/www/html/data/app.log
```

因为 Compose 挂载了 `./data:/var/www/html/data`，所以宿主机上可以直接查看：

```bash
tail -f data/app.log
```

同一份日志也会写到标准错误，可以通过 Docker 查看：

```bash
docker compose logs -f
```

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
- `GET /api/admin/logs`
- `POST /api/admin/balance`
- `GET /api/gallery`
- `POST /api/gallery/upload`
- `DELETE /api/gallery/:id`
- `POST /api/gallery/:id/move`
- `GET /media/:id`

## 已做保护

- 管理员邮箱和密码只保存在服务端环境变量里，不会发到前端
- 管理后台访问前必须先输入 `RECORDS_ACCESS_KEY`，验证通过后才会进入后台页面
- 同一邮箱和同一用户 ID 都会拦截重复领取
- 使用本地 `claims.json` 做持久化
- 配置 MySQL 后会使用数据库持久化，自动领取查重有数据库唯一键兜底
- 领取前先写入 `pending` 状态，避免并发重复发放
- 有基础 IP 限流
- 已接入服务端验证码，领取时必须输入正确验证码
- 验证码带有效期、尝试次数限制和刷新频率限制
- 已接入领取记录查询接口，必须提供 `RECORDS_ACCESS_KEY` 才能读取
- 管理后台可按邮箱给用户手动添加自定义余额，操作记录会写入 `data/claims.json`
- 管理后台可查看最近操作日志，用于确认给谁添加了余额
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
