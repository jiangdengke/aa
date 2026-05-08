const form = document.querySelector("#admin-form");
const accessKeyInput = document.querySelector("#access-key");
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

function setStatus(card, titleNode, messageNode, tone, title, message) {
  card.className = `status-card status-${tone}`;
  titleNode.textContent = title;
  messageNode.textContent = message;
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
    recordsBody.innerHTML = '<tr><td colspan="5" class="records-empty">暂无记录</td></tr>';
    return;
  }

  const statusMap = {
    completed: "已成功",
    pending: "处理中",
    failed: "失败"
  };

  recordsBody.innerHTML = items.map((item) => `
    <tr>
      <td>${item.email}</td>
      <td>${item.amount}</td>
      <td>${statusMap[item.status] || item.status}</td>
      <td>${formatDate(item.createdAt)}</td>
      <td>${formatDate(item.awardedAt)}</td>
    </tr>
  `).join("");
}

function getAccessKey() {
  return accessKeyInput.value.trim();
}

function galleryStatus(tone, title, message) {
  setStatus(galleryStatusCard, galleryStatusTitle, galleryStatusMessage, tone, title, message);
}

async function loadGalleryAdmin() {
  const accessKey = getAccessKey();
  if (!accessKey || !galleryAdminGrid) {
    galleryAdminGrid.innerHTML = '<div class="gallery-empty">输入访问密钥后可查看图片。</div>';
    return;
  }

  try {
    const response = await fetch("/api/gallery", {
      headers: {
        Authorization: `Bearer ${accessKey}`
      }
    });
    const payload = await response.json().catch(() => ({ items: [] }));
    const items = Array.isArray(payload.items) ? payload.items : [];

    if (!items.length) {
      galleryAdminGrid.innerHTML = '<div class="gallery-empty">暂无图片，请先上传。</div>';
      return;
    }

    galleryAdminGrid.innerHTML = items.map((item, index) => `
      <article class="gallery-admin-card" data-id="${item.id}">
        <img src="${item.url}" alt="${item.alt || item.title || "二维码"}" />
        <div class="gallery-admin-meta">
          <strong>${item.title || "未命名图片"}</strong>
          <span>${item.alt || "-"}</span>
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
  const accessKey = accessKeyInput.value.trim();

  if (!accessKey) {
    setStatus(statusCard, statusTitle, statusMessage, "error", "访问密钥不能为空", "请输入后端配置的访问密钥。");
    return;
  }

  setRecordsBusy(true);
  setStatus(statusCard, statusTitle, statusMessage, "pending", "正在查询", "正在读取领取记录。");

  try {
    const url = new URL("/api/admin/claims", window.location.origin);
    url.searchParams.set("search", searchEmailInput.value.trim());
    url.searchParams.set("status", statusFilter.value);
    url.searchParams.set("sortBy", sortBySelect.value);
    url.searchParams.set("sortOrder", sortOrderSelect.value);

    const response = await fetch(url, {
      headers: {
        Authorization: `Bearer ${accessKey}`
      }
    });

    const payload = await response.json().catch(() => ({
      message: "服务返回异常。"
    }));

    if (!response.ok) {
      renderRows([]);
      setStatus(statusCard, statusTitle, statusMessage, "error", "查询失败", payload.message || "无法读取记录。");
      return;
    }

    renderRows(payload.items || []);
    setStatus(statusCard, statusTitle, statusMessage, "success", "查询成功", `共找到 ${payload.total || 0} 条记录。`);
    await loadGalleryAdmin();
  } catch {
    renderRows([]);
    setStatus(statusCard, statusTitle, statusMessage, "error", "请求失败", "网络异常或服务不可用，请稍后再试。");
  } finally {
    setRecordsBusy(false);
  }
});

uploadForm?.addEventListener("submit", async (event) => {
  event.preventDefault();

  const accessKey = getAccessKey();
  const file = fileInput.files?.[0];

  if (!accessKey) {
    galleryStatus("error", "访问密钥不能为空", "请先输入访问密钥。");
    return;
  }

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
      headers: {
        Authorization: `Bearer ${accessKey}`
      },
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

  if (!id || !accessKey) {
    galleryStatus("error", "缺少访问密钥", "请先输入访问密钥。");
    return;
  }

  try {
    let response;
    if (action === "delete") {
      response = await fetch(`/api/gallery/${id}`, {
        method: "DELETE",
        headers: {
          Authorization: `Bearer ${accessKey}`
        }
      });
    } else {
      response = await fetch(`/api/gallery/${id}/move`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${accessKey}`,
          "Content-Type": "application/json"
        },
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
