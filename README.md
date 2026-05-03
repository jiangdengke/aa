# dogcoding 自助领 10 刀

这是一个最小可用的自助领取页：

- 用户在网页输入邮箱
- 服务端用管理员账号登录 `https://ai.laodog.top`
- 去用户管理搜索邮箱
- 找到用户后调用管理员余额接口加 `10`
- 每个账户只允许领取一次

## 文件结构

- `server.mjs`: 无依赖 Node 服务端
- `public/`: 静态页面
- `data/claims.json`: 领取记录，首次运行后自动生成

## 配置

先准备环境变量：

- `BASE_URL`: 你的站点地址，默认 `https://ai.laodog.top`
- `ADMIN_EMAIL`: 管理员邮箱
- `ADMIN_PASSWORD`: 管理员密码
- `RECORDS_ACCESS_KEY`: 查看领取记录的访问密钥
- `CLAIM_AMOUNT`: 赠送额度，默认 `10`
- `CLAIM_NOTES`: 写入后台余额记录的备注
- `RATE_LIMIT_MAX`: 单个 IP 10 分钟内最大请求次数，默认 `20`
- `CAPTCHA_TTL_SECONDS`: 验证码有效期，默认 `300`
- `CAPTCHA_MAX_ATTEMPTS`: 单个验证码最多尝试次数，默认 `5`
- `CAPTCHA_RATE_LIMIT_MAX`: 单个 IP 10 分钟内最多刷新验证码次数，默认 `40`

Node 20+ 可以直接用：

```bash
node --env-file=.env server.mjs
```

或者：

```bash
npm run start:env
```

启动后访问：

```text
http://127.0.0.1:3000
```

领取记录页：

```text
http://127.0.0.1:3000/admin.html
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

## 接口逻辑

当前实现直接调用了站点已有接口：

- `POST /api/v1/auth/login`
- `GET /api/v1/admin/users?search=邮箱`
- `POST /api/v1/admin/users/:id/balance`

余额接口使用：

- `operation: "add"`
- `balance: 10`

## 已做保护

- 管理员邮箱和密码只保存在服务端环境变量里，不会发到前端
- 同一邮箱和同一用户 ID 都会拦截重复领取
- 使用本地 `claims.json` 做持久化
- 领取前先写入 `pending` 状态，避免并发重复发放
- 有基础 IP 限流
- 已接入服务端验证码，领取时必须输入正确验证码
- 验证码带有效期、尝试次数限制和刷新频率限制
- 已接入领取记录查询接口，必须提供 `RECORDS_ACCESS_KEY` 才能读取

## 需要你知道的风险

如果页面只让用户输入邮箱就能领取，那么任何知道别人邮箱的人都可以替对方触发领取，且还能用返回消息判断这个邮箱是否存在。

如果你要正式上线，建议下一步至少补一个校验：

- 邮箱验证码
- 登录态校验
- 邀请码
- Cloudflare Turnstile / CAPTCHA

如果你要，我可以下一步直接把“邮箱验证码后才能领”的版本也接上。
