const form = document.querySelector("#admin-form");
const logoutButton = document.querySelector("#admin-logout");
const searchEmailInput = document.querySelector("#search-email");
const statusFilter = document.querySelector("#status-filter");
const sortBySelect = document.querySelector("#sort-by");
const sortOrderSelect = document.querySelector("#sort-order");
const recordsBody = document.querySelector("#records-body");
const recordsTotal = document.querySelector("#records-total");
const recordsSubmitButton = document.querySelector("#admin-submit");
const statusCard = document.querySelector("#admin-status");
const statusTitle = document.querySelector("#admin-status-title");
const statusMessage = document.querySelector("#admin-status-message");
const uploadForm = document.querySelector("#upload-form");
const titleInput = document.querySelector("#image-title");
const altInput = document.querySelector("#image-alt");
const fileInput = document.querySelector("#image-file");
const uploadSubmitButton = document.querySelector("#upload-submit");
const galleryStatusCard = document.querySelector("#gallery-status");
const galleryStatusTitle = document.querySelector("#gallery-status-title");
const galleryStatusMessage = document.querySelector("#gallery-status-message");
const galleryAdminGrid = document.querySelector("#gallery-admin-grid");
const balanceForm = document.querySelector("#balance-form");
const balanceEmailInput = document.querySelector("#balance-email");
const balanceAmountInput = document.querySelector("#balance-amount");
const balanceNotesInput = document.querySelector("#balance-notes");
const balanceSubmitButton = document.querySelector("#balance-submit");
const balanceStatusCard = document.querySelector("#balance-status");
const balanceStatusTitle = document.querySelector("#balance-status-title");
const balanceStatusMessage = document.querySelector("#balance-status-message");
const logsBody = document.querySelector("#logs-body");
const logsRefreshButton = document.querySelector("#logs-refresh");
const tabButtons = document.querySelectorAll("[data-tab-target]");
const tabPanels = document.querySelectorAll(".admin-panel");

function setStatus(card, titleNode, messageNode, tone, title, message) {
  card.className = `status-card status-${tone}`;
  titleNode.textContent = title;
  messageNode.textContent = message;
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function setRecordsBusy(busy) {
  recordsSubmitButton.disabled = busy;
  recordsSubmitButton.classList.toggle("is-processing", busy);
  recordsSubmitButton.querySelector(".submit-button__label").textContent = busy ? "查询中" : "查询记录";
}

function setUploadBusy(busy) {
  uploadSubmitButton.disabled = busy;
  uploadSubmitButton.classList.toggle("is-processing", busy);
  uploadSubmitButton.querySelector(".submit-button__label").textContent = busy ? "上传中" : "上传图片";
}

function setBalanceBusy(busy) {
  balanceSubmitButton.disabled = busy;
  balanceSubmitButton.classList.toggle("is-processing", busy);
  balanceSubmitButton.querySelector(".submit-button__label").textContent = busy ? "添加中" : "添加余额";
}

function formatDate(value) {
  if (!value) {
    return "-";
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return date.toLocaleString("zh-CN", { hour12: false });
}

function renderRows(items) {
  recordsTotal.textContent = `${items.length} 条`;
  if (!items.length) {
    recordsBody.innerHTML = '<tr><td colspan="7" class="records-empty">暂无记录</td></tr>';
    return;
  }

  const statusMap = {
    completed: "已成功",
    pending: "处理中",
    failed: "失败"
  };
  const typeMap = {
    auto_claim: "自助领取",
    manual_balance: "手动加余额"
  };

  recordsBody.innerHTML = items.map((item) => `
    <tr>
      <td>${escapeHtml(item.email)}</td>
      <td>${escapeHtml(typeMap[item.type] || item.type || "-")}</td>
      <td>${escapeHtml(item.amount)}</td>
      <td>${escapeHtml(statusMap[item.status] || item.status)}</td>
      <td>${formatDate(item.createdAt)}</td>
      <td>${formatDate(item.awardedAt)}</td>
      <td>${escapeHtml(item.notes || "-")}</td>
    </tr>
  `).join("");
}

function getAccessKey() {
  return "";
}

function isUnauthorized(response, payload) {
  return response.status === 401 || payload?.error === "unauthorized";
}

function unauthorizedMessage() {
  return "登录状态已失效，请刷新页面重新输入后台访问密钥。";
}

function adminHeaders(accessKey, extra = {}) {
  const headers = { ...extra };
  if (accessKey) {
    headers.Authorization = `Bearer ${accessKey}`;
    headers["X-Access-Key"] = accessKey;
  }
  return headers;
}

function galleryStatus(tone, title, message) {
  setStatus(galleryStatusCard, galleryStatusTitle, galleryStatusMessage, tone, title, message);
}

function balanceStatus(tone, title, message) {
  setStatus(balanceStatusCard, balanceStatusTitle, balanceStatusMessage, tone, title, message);
}

function activateTab(targetId) {
  tabButtons.forEach((button) => {
    const isActive = button.dataset.tabTarget === targetId;
    button.classList.toggle("is-active", isActive);
  });
  tabPanels.forEach((panel) => {
    const isActive = panel.id === targetId;
    panel.classList.toggle("is-active", isActive);
    panel.hidden = !isActive;
  });

  if (targetId === "gallery-panel") {
    loadGalleryAdmin();
    return;
  }
  loadLogs();
}

function renderLogs(items) {
  if (!logsBody) {
    return;
  }
  if (!items.length) {
    logsBody.innerHTML = '<tr><td colspan="7" class="records-empty">暂无日志</td></tr>';
    return;
  }

  logsBody.innerHTML = items.map((item) => {
    const context = item.context || {};
    const detail = context.error || context.title || context.database || context.direction || "-";
    return `
      <tr>
        <td>${formatDate(item.time)}</td>
        <td>${escapeHtml(item.level || "-")}</td>
        <td>${escapeHtml(item.message || "-")}</td>
        <td>${escapeHtml(context.email || "-")}</td>
        <td>${escapeHtml(context.userId || "-")}</td>
        <td>${escapeHtml(context.amount || "-")}</td>
        <td>${escapeHtml(detail)}</td>
      </tr>
    `;
  }).join("");
}

async function loadLogs() {
  const accessKey = getAccessKey();
  if (!logsBody) {
    renderLogs([]);
    return;
  }

  logsRefreshButton.disabled = true;
  try {
    const response = await fetch("/api/admin/logs?limit=200", {
      headers: adminHeaders(accessKey)
    });
    const payload = await response.json().catch(() => ({ items: [], message: "日志返回异常。" }));
    if (!response.ok) {
      renderLogs([{
        time: new Date().toISOString(),
        level: "ERROR",
        message: isUnauthorized(response, payload) ? unauthorizedMessage() : (payload.message || "日志加载失败"),
        context: {}
      }]);
      return;
    }
    renderLogs(Array.isArray(payload.items) ? payload.items : []);
  } catch {
    renderLogs([]);
  } finally {
    logsRefreshButton.disabled = false;
  }
}

async function loadGalleryAdmin() {
  const accessKey = getAccessKey();
  if (!galleryAdminGrid) {
    return;
  }

  try {
    const response = await fetch("/api/gallery", {
      headers: adminHeaders(accessKey)
    });
    const payload = await response.json().catch(() => ({ items: [] }));
    const items = Array.isArray(payload.items) ? payload.items : [];

    if (!items.length) {
      galleryAdminGrid.innerHTML = '<div class="gallery-empty">暂无图片，请先上传。</div>';
      return;
    }

    galleryAdminGrid.innerHTML = items.map((item, index) => `
      <article class="gallery-admin-card" data-id="${escapeHtml(item.id)}">
        <img src="${escapeHtml(item.url)}" alt="${escapeHtml(item.alt || item.title || "二维码")}" />
        <div class="gallery-admin-meta">
          <strong>${escapeHtml(item.title || "未命名图片")}</strong>
          <span>${escapeHtml(item.alt || "-")}</span>
        </div>
        <div class="gallery-admin-actions">
          <button type="button" data-action="up" ${index === 0 ? "disabled" : ""}>上移</button>
          <button type="button" data-action="down" ${index === items.length - 1 ? "disabled" : ""}>下移</button>
          <button type="button" data-action="delete">删除</button>
        </div>
      </article>
    `).join("");
  } catch {
    galleryAdminGrid.innerHTML = '<div class="gallery-empty">图片列表加载失败。</div>';
  }
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const accessKey = getAccessKey();

  setRecordsBusy(true);
  setStatus(statusCard, statusTitle, statusMessage, "pending", "正在查询", "正在读取领取记录。");

  try {
    const url = new URL("/api/admin/claims", window.location.origin);
    url.searchParams.set("search", searchEmailInput.value.trim());
    url.searchParams.set("status", statusFilter.value);
    url.searchParams.set("sortBy", sortBySelect.value);
    url.searchParams.set("sortOrder", sortOrderSelect.value);

    const response = await fetch(url, {
      headers: adminHeaders(accessKey)
    });

    const payload = await response.json().catch(() => ({
      message: "服务返回异常。"
    }));

    if (!response.ok) {
      renderRows([]);
      setStatus(statusCard, statusTitle, statusMessage, "error", "查询失败", isUnauthorized(response, payload) ? unauthorizedMessage() : (payload.message || "无法读取记录。"));
      return;
    }

    renderRows(payload.items || []);
    setStatus(statusCard, statusTitle, statusMessage, "success", "查询成功", `共找到 ${payload.total || 0} 条记录。`);
    await loadGalleryAdmin();
    await loadLogs();
  } catch {
    renderRows([]);
    setStatus(statusCard, statusTitle, statusMessage, "error", "请求失败", "网络异常或服务不可用，请稍后再试。");
  } finally {
    setRecordsBusy(false);
  }
});

balanceForm?.addEventListener("submit", async (event) => {
  event.preventDefault();

  const accessKey = getAccessKey();
  const email = balanceEmailInput.value.trim();
  const amount = Number(balanceAmountInput.value);

  if (!email) {
    balanceStatus("error", "邮箱不能为空", "请输入需要添加余额的用户邮箱。");
    return;
  }

  if (!Number.isFinite(amount) || amount <= 0) {
    balanceStatus("error", "金额无效", "请输入大于 0 的金额。");
    return;
  }

  setBalanceBusy(true);
  balanceStatus("pending", "正在添加", "正在核验用户并添加余额。");

  try {
    const response = await fetch("/api/admin/balance", {
      method: "POST",
      headers: adminHeaders(accessKey, {
        "Content-Type": "application/json"
      }),
      body: JSON.stringify({
        email,
        amount,
        notes: balanceNotesInput.value.trim()
      })
    });

    const payload = await response.json().catch(() => ({ message: "操作失败。" }));
    if (!response.ok) {
      balanceStatus("error", "添加失败", isUnauthorized(response, payload) ? unauthorizedMessage() : (payload.message || "无法添加余额。"));
      if (!isUnauthorized(response, payload)) {
        await loadLogs();
      }
      return;
    }

    balanceStatus("success", "添加成功", payload.message || "余额已添加。");
    balanceForm.reset();
    form.requestSubmit();
    await loadLogs();
  } catch {
    balanceStatus("error", "请求失败", "网络异常或服务不可用，请稍后再试。");
  } finally {
    setBalanceBusy(false);
  }
});

logsRefreshButton?.addEventListener("click", () => {
  loadLogs();
});

logoutButton?.addEventListener("click", async () => {
  logoutButton.disabled = true;
  try {
    await fetch("/api/admin/logout", { method: "POST" });
  } finally {
    window.location.href = "/admin.html";
  }
});

tabButtons.forEach((button) => {
  button.addEventListener("click", () => {
    activateTab(button.dataset.tabTarget);
  });
});

uploadForm?.addEventListener("submit", async (event) => {
  event.preventDefault();

  const accessKey = getAccessKey();
  const file = fileInput.files?.[0];

  if (!file) {
    galleryStatus("error", "请选择图片", "请先选择要上传的二维码图片。");
    return;
  }

  setUploadBusy(true);
  galleryStatus("pending", "正在上传", "正在保存图片并更新首页。");

  try {
    const data = new FormData();
    data.append("title", titleInput.value.trim());
    data.append("alt", altInput.value.trim());
    data.append("image", file, file.name);

    const response = await fetch("/api/gallery/upload", {
      method: "POST",
      headers: adminHeaders(accessKey),
      body: data
    });

    const payload = await response.json().catch(() => ({ message: "上传失败。" }));
    if (!response.ok) {
      galleryStatus("error", "上传失败", payload.message || "无法保存图片。");
      return;
    }

    uploadForm.reset();
    galleryStatus("success", "上传成功", "二维码图片已更新到首页。");
    await loadGalleryAdmin();
  } catch {
    galleryStatus("error", "请求失败", "网络异常或服务不可用，请稍后再试。");
  } finally {
    setUploadBusy(false);
  }
});

galleryAdminGrid?.addEventListener("click", async (event) => {
  const button = event.target.closest("button[data-action]");
  if (!button) {
    return;
  }

  const action = button.dataset.action;
  const card = button.closest("[data-id]");
  const id = card?.dataset.id;
  const accessKey = getAccessKey();

  if (!id) {
    galleryStatus("error", "缺少图片 ID", "无法识别需要操作的图片。");
    return;
  }

  try {
    let response;
    if (action === "delete") {
      response = await fetch(`/api/gallery/${id}`, {
        method: "DELETE",
        headers: adminHeaders(accessKey)
      });
    } else {
      response = await fetch(`/api/gallery/${id}/move`, {
        method: "POST",
        headers: adminHeaders(accessKey, {
          "Content-Type": "application/json"
        }),
        body: JSON.stringify({ direction: action })
      });
    }

    const payload = await response.json().catch(() => ({ message: "操作失败。" }));
    if (!response.ok) {
      galleryStatus("error", "操作失败", payload.message || "无法更新图片。");
      return;
    }

    galleryStatus("success", "操作成功", action === "delete" ? "图片已删除。" : "图片顺序已更新。");
    await loadGalleryAdmin();
  } catch {
    galleryStatus("error", "请求失败", "网络异常或服务不可用，请稍后再试。");
  }
});
