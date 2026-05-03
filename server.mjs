import { randomBytes, randomUUID } from "node:crypto";
import http from "node:http";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { promises as fs } from "node:fs";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PUBLIC_DIR = path.join(__dirname, "public");
const DATA_DIR = path.join(__dirname, "data");
const CLAIMS_FILE = path.join(DATA_DIR, "claims.json");
const API_PREFIX = "/api/v1";

const PORT = Number(process.env.PORT || 3000);
const BASE_URL = (process.env.BASE_URL || "https://ai.laodog.top").replace(/\/+$/, "");
const ADMIN_EMAIL = (process.env.ADMIN_EMAIL || "").trim();
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || "";
const RECORDS_ACCESS_KEY = (process.env.RECORDS_ACCESS_KEY || "").trim();
const CLAIM_AMOUNT = Number(process.env.CLAIM_AMOUNT || "10");
const CLAIM_NOTES = (process.env.CLAIM_NOTES || "Self-service bonus claim").trim();
const RATE_LIMIT_MAX = Number(process.env.RATE_LIMIT_MAX || "20");
const CAPTCHA_TTL_SECONDS = Number(process.env.CAPTCHA_TTL_SECONDS || "300");
const CAPTCHA_MAX_ATTEMPTS = Number(process.env.CAPTCHA_MAX_ATTEMPTS || "5");
const CAPTCHA_RATE_LIMIT_MAX = Number(process.env.CAPTCHA_RATE_LIMIT_MAX || "40");

const TOKEN_REFRESH_SKEW_MS = 30_000;
const RATE_LIMIT_WINDOW_MS = 10 * 60 * 1000;
const MAX_BODY_BYTES = 32 * 1024;
const CAPTCHA_CHARS = "23456789ABCDEFGHJKLMNPQRSTUVWXYZ";

const adminSession = {
  accessToken: "",
  refreshToken: "",
  expiresAt: 0
};

const rateLimitBuckets = new Map();
const captchaChallenges = new Map();

let claimsLock = Promise.resolve();

class AppError extends Error {
  constructor(status, code, message, details = null) {
    super(message);
    this.name = "AppError";
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

function assertConfig() {
  if (!BASE_URL) {
    throw new AppError(500, "config_error", "BASE_URL 未配置。");
  }
  if (!ADMIN_EMAIL || !ADMIN_PASSWORD) {
    throw new AppError(500, "config_error", "ADMIN_EMAIL 或 ADMIN_PASSWORD 未配置。");
  }
  if (!Number.isFinite(CLAIM_AMOUNT) || CLAIM_AMOUNT <= 0) {
    throw new AppError(500, "config_error", "CLAIM_AMOUNT 必须是大于 0 的数字。");
  }
}

function assertRecordsAccessConfigured() {
  if (!RECORDS_ACCESS_KEY) {
    throw new AppError(500, "config_error", "RECORDS_ACCESS_KEY 未配置。");
  }
}

function createClaimsStore() {
  return {
    version: 1,
    claims: []
  };
}

function normalizeEmail(email) {
  return String(email || "").trim().toLowerCase();
}

function normalizeCaptchaCode(value) {
  return String(value || "").trim().toUpperCase();
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function getClientIp(req) {
  const forwarded = req.headers["x-forwarded-for"];
  if (typeof forwarded === "string" && forwarded.trim()) {
    return forwarded.split(",")[0].trim();
  }
  return req.socket.remoteAddress || "unknown";
}

function getBearerToken(req) {
  const header = req.headers.authorization;
  if (typeof header !== "string") {
    return "";
  }
  const match = header.match(/^Bearer\s+(.+)$/i);
  return match ? match[1].trim() : "";
}

function requireRecordsAccess(req) {
  assertRecordsAccessConfigured();
  const token = getBearerToken(req);
  if (!token || token !== RECORDS_ACCESS_KEY) {
    throw new AppError(401, "unauthorized", "无权查看领取记录。");
  }
}

function json(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    "Content-Length": Buffer.byteLength(body)
  });
  res.end(body);
}

function getContentType(filePath) {
  const ext = path.extname(filePath).toLowerCase();
  switch (ext) {
    case ".html":
      return "text/html; charset=utf-8";
    case ".css":
      return "text/css; charset=utf-8";
    case ".js":
      return "application/javascript; charset=utf-8";
    case ".json":
      return "application/json; charset=utf-8";
    case ".svg":
      return "image/svg+xml";
    case ".png":
      return "image/png";
    case ".jpg":
    case ".jpeg":
      return "image/jpeg";
    case ".ico":
      return "image/x-icon";
    default:
      return "application/octet-stream";
  }
}

async function ensureDataDir() {
  await fs.mkdir(DATA_DIR, { recursive: true });
}

async function loadClaimsStore() {
  await ensureDataDir();
  try {
    const raw = await fs.readFile(CLAIMS_FILE, "utf8");
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== "object" || !Array.isArray(parsed.claims)) {
      return createClaimsStore();
    }
    return parsed;
  } catch (error) {
    if (error.code === "ENOENT") {
      return createClaimsStore();
    }
    throw error;
  }
}

async function saveClaimsStore(store) {
  await ensureDataDir();
  const tempFile = `${CLAIMS_FILE}.${process.pid}.tmp`;
  const payload = JSON.stringify(store, null, 2);
  await fs.writeFile(tempFile, payload, "utf8");
  await fs.rename(tempFile, CLAIMS_FILE);
}

function withClaimsLock(task) {
  const run = async () => task();
  const next = claimsLock.then(run, run);
  claimsLock = next.catch(() => {});
  return next;
}

function cleanupRateLimitBucket(bucket, now) {
  bucket.hits = bucket.hits.filter((timestamp) => now - timestamp < RATE_LIMIT_WINDOW_MS);
}

function checkRateLimit(scope, ip, max = RATE_LIMIT_MAX) {
  const now = Date.now();
  const key = `${scope}:${ip}`;
  const bucket = rateLimitBuckets.get(key) || { hits: [] };
  cleanupRateLimitBucket(bucket, now);
  if (bucket.hits.length >= max) {
    rateLimitBuckets.set(key, bucket);
    throw new AppError(429, "rate_limited", "请求过于频繁，请稍后再试。");
  }
  bucket.hits.push(now);
  rateLimitBuckets.set(key, bucket);
}

function randomInt(max) {
  return randomBytes(1)[0] % max;
}

function generateCaptchaText(length = 5) {
  const bytes = randomBytes(length);
  let text = "";
  for (let i = 0; i < length; i += 1) {
    text += CAPTCHA_CHARS[bytes[i] % CAPTCHA_CHARS.length];
  }
  return text;
}

function createCaptchaSvg(text) {
  const width = 160;
  const height = 56;
  const palette = ["#59eeff", "#ff4fb1", "#5b7cff", "#eef8ff"];

  const lines = Array.from({ length: 6 }, () => {
    const x1 = randomInt(width);
    const y1 = randomInt(height);
    const x2 = randomInt(width);
    const y2 = randomInt(height);
    const color = palette[randomInt(palette.length)];
    const opacity = (0.14 + randomInt(18) / 100).toFixed(2);
    return `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${color}" stroke-opacity="${opacity}" stroke-width="1.2" />`;
  }).join("");

  const dots = Array.from({ length: 14 }, () => {
    const cx = randomInt(width);
    const cy = randomInt(height);
    const r = 0.6 + randomInt(18) / 10;
    const color = palette[randomInt(palette.length)];
    const opacity = (0.08 + randomInt(20) / 100).toFixed(2);
    return `<circle cx="${cx}" cy="${cy}" r="${r.toFixed(1)}" fill="${color}" fill-opacity="${opacity}" />`;
  }).join("");

  const chars = text.split("").map((char, index) => {
    const x = 18 + index * 26 + randomInt(6);
    const y = 32 + randomInt(10);
    const rotate = randomInt(31) - 15;
    const color = palette[index % 3];
    return `<text x="${x}" y="${y}" fill="${color}" font-size="28" font-family="Verdana, Arial, sans-serif" font-weight="700" transform="rotate(${rotate} ${x} ${y})">${char}</text>`;
  }).join("");

  return `
    <svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" role="img" aria-label="验证码">
      <rect width="${width}" height="${height}" rx="8" fill="#08101d" />
      <rect x="1" y="1" width="${width - 2}" height="${height - 2}" rx="7" fill="none" stroke="rgba(89,238,255,0.32)" />
      <path d="M0 18 H160" stroke="rgba(255,255,255,0.06)" />
      <path d="M0 40 H160" stroke="rgba(255,255,255,0.05)" />
      ${lines}
      ${dots}
      ${chars}
    </svg>
  `.trim();
}

function createCaptchaChallenge(ip) {
  const answer = generateCaptchaText();
  const token = randomUUID();
  const svg = createCaptchaSvg(answer);

  captchaChallenges.set(token, {
    answer,
    ip,
    attempts: 0,
    expiresAt: Date.now() + CAPTCHA_TTL_SECONDS * 1000
  });

  return {
    token,
    image: `data:image/svg+xml;base64,${Buffer.from(svg).toString("base64")}`
  };
}

function cleanupCaptchaChallenges(now = Date.now()) {
  for (const [token, challenge] of captchaChallenges.entries()) {
    if (challenge.expiresAt <= now || challenge.attempts >= CAPTCHA_MAX_ATTEMPTS) {
      captchaChallenges.delete(token);
    }
  }
}

function verifyCaptcha(token, code, ip) {
  cleanupCaptchaChallenges();

  if (!token || !code) {
    throw new AppError(400, "captcha_required", "请输入验证码。");
  }

  const challenge = captchaChallenges.get(String(token));
  if (!challenge) {
    throw new AppError(400, "captcha_expired", "验证码已过期，请刷新后重试。");
  }

  if (challenge.ip !== ip) {
    captchaChallenges.delete(String(token));
    throw new AppError(400, "captcha_invalid", "验证码无效，请刷新后重试。");
  }

  if (challenge.expiresAt <= Date.now()) {
    captchaChallenges.delete(String(token));
    throw new AppError(400, "captcha_expired", "验证码已过期，请刷新后重试。");
  }

  challenge.attempts += 1;
  const normalized = normalizeCaptchaCode(code);
  if (normalized !== challenge.answer) {
    if (challenge.attempts >= CAPTCHA_MAX_ATTEMPTS) {
      captchaChallenges.delete(String(token));
    }
    throw new AppError(400, "captcha_invalid", "验证码错误，请重试。");
  }

  captchaChallenges.delete(String(token));
}

async function readJsonBody(req) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) {
      throw new AppError(413, "payload_too_large", "请求体过大。");
    }
    chunks.push(chunk);
  }
  if (chunks.length === 0) {
    return {};
  }
  const raw = Buffer.concat(chunks).toString("utf8");
  try {
    return JSON.parse(raw);
  } catch {
    throw new AppError(400, "invalid_json", "请求体必须是 JSON。");
  }
}

function extractErrorMessage(payload, fallback) {
  if (!payload || typeof payload !== "object") {
    return fallback;
  }
  if (typeof payload.detail === "string" && payload.detail.trim()) {
    return payload.detail;
  }
  if (typeof payload.message === "string" && payload.message.trim()) {
    return payload.message;
  }
  if (typeof payload.error === "string" && payload.error.trim()) {
    return payload.error;
  }
  if (payload.data && typeof payload.data === "object") {
    return extractErrorMessage(payload.data, fallback);
  }
  return fallback;
}

function unwrapApiPayload(payload) {
  if (
    payload &&
    typeof payload === "object" &&
    "code" in payload &&
    payload.code === 0 &&
    "data" in payload
  ) {
    return payload.data;
  }
  return payload;
}

async function requestUpstream(pathname, options = {}) {
  const url = new URL(pathname, `${BASE_URL}/`);
  if (options.query) {
    for (const [key, value] of Object.entries(options.query)) {
      if (value === undefined || value === null || value === "") {
        continue;
      }
      url.searchParams.set(key, String(value));
    }
  }

  const headers = {
    Accept: "application/json"
  };

  if (options.body !== undefined) {
    headers["Content-Type"] = "application/json";
  }

  if (options.token) {
    headers.Authorization = `Bearer ${options.token}`;
  }

  const response = await fetch(url, {
    method: options.method || "GET",
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body)
  });

  const text = await response.text();
  let payload = null;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = text;
    }
  }

  if (!response.ok) {
    throw new AppError(
      response.status,
      "upstream_error",
      extractErrorMessage(payload, `上游接口请求失败: ${response.status}`),
      payload
    );
  }

  return payload;
}

function clearAdminSession() {
  adminSession.accessToken = "";
  adminSession.refreshToken = "";
  adminSession.expiresAt = 0;
}

async function loginAsAdmin(force = false) {
  assertConfig();
  if (!force && adminSession.accessToken && Date.now() < adminSession.expiresAt - TOKEN_REFRESH_SKEW_MS) {
    return adminSession.accessToken;
  }

  const payload = unwrapApiPayload(
    await requestUpstream(`${API_PREFIX}/auth/login`, {
      method: "POST",
      body: {
        email: ADMIN_EMAIL,
        password: ADMIN_PASSWORD
      }
    })
  );

  if (!payload || typeof payload !== "object" || !payload.access_token) {
    throw new AppError(
      502,
      "admin_login_failed",
      "管理员登录失败，请确认账号密码是否正确，且当前账号未启用额外 2FA。"
    );
  }

  adminSession.accessToken = payload.access_token;
  adminSession.refreshToken = payload.refresh_token || "";
  adminSession.expiresAt = Date.now() + Number(payload.expires_in || 3600) * 1000;
  return adminSession.accessToken;
}

async function callAdminApi(pathname, options = {}, attempt = 0) {
  const token = await loginAsAdmin(attempt > 0);
  try {
    return await requestUpstream(pathname, {
      ...options,
      token
    });
  } catch (error) {
    if (error instanceof AppError && error.status === 401 && attempt === 0) {
      clearAdminSession();
      return callAdminApi(pathname, options, 1);
    }
    throw error;
  }
}

function normalizeUsersList(payload) {
  const data = unwrapApiPayload(payload);
  if (Array.isArray(data)) {
    return { items: data, total: data.length };
  }
  if (data && typeof data === "object" && Array.isArray(data.items)) {
    return {
      items: data.items,
      total: Number(data.total ?? data.items.length)
    };
  }
  throw new AppError(502, "unexpected_users_response", "用户列表接口返回格式异常。");
}

async function findUserByEmail(email) {
  const pageSize = 100;
  for (let page = 1; page <= 3; page += 1) {
    const result = normalizeUsersList(
      await callAdminApi(`${API_PREFIX}/admin/users`, {
        query: {
          page,
          page_size: pageSize,
          search: email
        }
      })
    );

    const exact = result.items.find((item) => normalizeEmail(item.email) === email);
    if (exact) {
      return exact;
    }

    if (result.items.length < pageSize || page * pageSize >= result.total) {
      break;
    }
  }
  return null;
}

async function addUserBalance(userId, amount, claimId) {
  const payload = await callAdminApi(`${API_PREFIX}/admin/users/${userId}/balance`, {
    method: "POST",
    body: {
      balance: amount,
      operation: "add",
      notes: `${CLAIM_NOTES} [claim:${claimId}]`
    }
  });
  return unwrapApiPayload(payload);
}

function findActiveClaim(store, predicate) {
  return store.claims.find((claim) => claim.status !== "failed" && predicate(claim));
}

function compareValues(a, b, direction = "desc") {
  const normalizedDirection = direction === "asc" ? 1 : -1;
  if (a === b) {
    return 0;
  }
  if (a === undefined || a === null) {
    return 1 * normalizedDirection;
  }
  if (b === undefined || b === null) {
    return -1 * normalizedDirection;
  }
  if (a > b) {
    return 1 * normalizedDirection;
  }
  if (a < b) {
    return -1 * normalizedDirection;
  }
  return 0;
}

function sortClaims(items, sortBy = "awardedAt", sortOrder = "desc") {
  const key = ["email", "createdAt", "awardedAt", "status", "amount"].includes(sortBy)
    ? sortBy
    : "awardedAt";
  const direction = sortOrder === "asc" ? "asc" : "desc";

  items.sort((left, right) => {
    if (key === "email" || key === "status") {
      return compareValues(String(left[key] || ""), String(right[key] || ""), direction);
    }
    return compareValues(left[key], right[key], direction);
  });
}

async function listClaims(options = {}) {
  const store = await loadClaimsStore();
  const search = normalizeEmail(options.search || "");
  let items = [...store.claims];

  if (search) {
    items = items.filter((claim) => claim.normalizedEmail.includes(search));
  }

  if (options.status) {
    items = items.filter((claim) => claim.status === options.status);
  }

  sortClaims(items, options.sortBy, options.sortOrder);

  return items.map((claim) => ({
    id: claim.id,
    email: claim.email,
    userId: claim.userId,
    amount: claim.amount,
    status: claim.status,
    createdAt: claim.createdAt,
    awardedAt: claim.awardedAt,
    failedAt: claim.failedAt,
    remoteIp: claim.remoteIp,
    errorMessage: claim.errorMessage
  }));
}

async function processClaim(email, remoteIp) {
  return withClaimsLock(async () => {
    const store = await loadClaimsStore();
    const existingByEmail = findActiveClaim(
      store,
      (claim) => claim.normalizedEmail === email
    );

    if (existingByEmail?.status === "completed") {
      throw new AppError(409, "already_claimed", "该邮箱已经领取过 10 刀。");
    }

    if (existingByEmail?.status === "pending") {
      throw new AppError(409, "claim_pending", "该邮箱的领取请求正在处理中，请稍后再试。");
    }

    const user = await findUserByEmail(email);
    if (!user) {
      throw new AppError(404, "user_not_found", "无该账户。");
    }

    const userId = String(user.id);
    const existingByUserId = findActiveClaim(
      store,
      (claim) => String(claim.userId) === userId
    );

    if (existingByUserId?.status === "completed") {
      throw new AppError(409, "already_claimed", "该账户已经领取过 10 刀。");
    }

    if (existingByUserId?.status === "pending") {
      throw new AppError(409, "claim_pending", "该账户的领取请求正在处理中，请稍后再试。");
    }

    const now = new Date().toISOString();
    const record = {
      id: randomUUID(),
      userId,
      email: user.email,
      normalizedEmail: email,
      amount: CLAIM_AMOUNT,
      status: "pending",
      remoteIp,
      createdAt: now,
      awardedAt: null,
      failedAt: null,
      errorMessage: null
    };

    store.claims.push(record);
    await saveClaimsStore(store);

    try {
      await addUserBalance(user.id, CLAIM_AMOUNT, record.id);
      record.status = "completed";
      record.awardedAt = new Date().toISOString();
      await saveClaimsStore(store);
      return {
        user: {
          id: user.id,
          email: user.email
        },
        amount: CLAIM_AMOUNT,
        awardedAt: record.awardedAt
      };
    } catch (error) {
      record.status = "failed";
      record.failedAt = new Date().toISOString();
      record.errorMessage = error instanceof Error ? error.message : "未知错误";
      await saveClaimsStore(store);
      throw error;
    }
  });
}

async function serveStaticFile(req, res, pathname) {
  const safePath = pathname === "/" ? "/index.html" : pathname;
  const filePath = path.join(PUBLIC_DIR, path.normalize(safePath));

  if (!filePath.startsWith(PUBLIC_DIR)) {
    json(res, 404, { error: "not_found", message: "Not found." });
    return;
  }

  try {
    const stats = await fs.stat(filePath);
    const resolvedPath = stats.isDirectory() ? path.join(filePath, "index.html") : filePath;
    const content = await fs.readFile(resolvedPath);
    res.writeHead(200, {
      "Content-Type": getContentType(resolvedPath),
      "Cache-Control": resolvedPath.endsWith(".html") ? "no-store" : "public, max-age=300",
      "Content-Length": content.length
    });
    res.end(content);
  } catch (error) {
    if (error.code === "ENOENT") {
      json(res, 404, { error: "not_found", message: "Not found." });
      return;
    }
    throw error;
  }
}

function handleError(res, error) {
  if (error instanceof AppError) {
    json(res, error.status, {
      error: error.code,
      message: error.message
    });
    return;
  }

  console.error(error);
  json(res, 500, {
    error: "internal_error",
    message: "服务异常，请稍后再试。"
  });
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);

    if (req.method === "GET" && url.pathname === "/api/health") {
      let configOk = true;
      try {
        assertConfig();
      } catch {
        configOk = false;
      }
      json(res, 200, {
        ok: true,
        configOk
      });
      return;
    }

    if (req.method === "GET" && url.pathname === "/api/config") {
      json(res, 200, {
        claimAmount: CLAIM_AMOUNT,
        title: "dogcoding 额度领取",
        captchaRequired: true
      });
      return;
    }

    if (req.method === "GET" && url.pathname === "/api/captcha") {
      const ip = getClientIp(req);
      checkRateLimit("captcha", ip, CAPTCHA_RATE_LIMIT_MAX);
      const captcha = createCaptchaChallenge(ip);
      json(res, 200, captcha);
      return;
    }

    if (req.method === "POST" && url.pathname === "/api/claim") {
      const ip = getClientIp(req);
      checkRateLimit("claim", ip);
      assertConfig();

      const body = await readJsonBody(req);
      const normalizedEmail = normalizeEmail(body.email);

      if (!isValidEmail(normalizedEmail)) {
        throw new AppError(400, "invalid_email", "请输入有效邮箱。");
      }

      verifyCaptcha(body.captchaToken, body.captchaCode, ip);

      const result = await processClaim(normalizedEmail, ip);
      json(res, 200, {
        ok: true,
        message: `已为 ${result.user.email} 发放 ${result.amount} 刀。`,
        user: result.user,
        amount: result.amount,
        awardedAt: result.awardedAt
      });
      return;
    }

    if (req.method === "GET" && url.pathname === "/api/admin/claims") {
      requireRecordsAccess(req);
      const records = await listClaims({
        search: url.searchParams.get("search") || "",
        status: url.searchParams.get("status") || "",
        sortBy: url.searchParams.get("sortBy") || "awardedAt",
        sortOrder: url.searchParams.get("sortOrder") || "desc"
      });
      json(res, 200, {
        items: records,
        total: records.length
      });
      return;
    }

    if (req.method !== "GET" && req.method !== "HEAD" && !url.pathname.startsWith("/api/")) {
      json(res, 405, {
        error: "method_not_allowed",
        message: "Method not allowed."
      });
      return;
    }

    await serveStaticFile(req, res, url.pathname);
  } catch (error) {
    handleError(res, error);
  }
});

setInterval(() => {
  cleanupCaptchaChallenges();
}, 60_000).unref();

server.listen(PORT, () => {
  console.log(`Bonus claim service listening on http://0.0.0.0:${PORT}`);
});
