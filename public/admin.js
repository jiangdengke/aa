const form = document.querySelector("#admin-form");
const accessKeyInput = document.querySelector("#access-key");
const searchEmailInput = document.querySelector("#search-email");
const statusFilter = document.querySelector("#status-filter");
const sortBySelect = document.querySelector("#sort-by");
const sortOrderSelect = document.querySelector("#sort-order");
const recordsBody = document.querySelector("#records-body");
const recordsTotal = document.querySelector("#records-total");
const submitButton = document.querySelector("#admin-submit");
const statusCard = document.querySelector("#admin-status");
const statusTitle = document.querySelector("#admin-status-title");
const statusMessage = document.querySelector("#admin-status-message");

function setStatus(tone, title, message) {
  statusCard.className = `status-card status-${tone}`;
  statusTitle.textContent = title;
  statusMessage.textContent = message;
}

function setBusy(busy) {
  submitButton.disabled = busy;
  submitButton.classList.toggle("is-processing", busy);
  submitButton.querySelector(".submit-button__label").textContent = busy ? "查询中" : "查看记录";
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
    recordsBody.innerHTML = '<tr><td colspan="4" class="records-empty">暂无记录</td></tr>';
    return;
  }

  recordsBody.innerHTML = items.map((item) => `
    <tr>
      <td>${item.email}</td>
      <td>${item.amount}</td>
      <td>${item.status}</td>
      <td>${formatDate(item.awardedAt || item.createdAt)}</td>
    </tr>
  `).join("");
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const accessKey = accessKeyInput.value.trim();

  if (!accessKey) {
    setStatus("error", "访问密钥不能为空", "请输入后端配置的访问密钥。");
    return;
  }

  setBusy(true);
  setStatus("pending", "正在查询", "正在读取领取记录。");

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
      setStatus("error", "查询失败", payload.message || "无法读取记录。");
      return;
    }

    renderRows(payload.items || []);
    setStatus("success", "查询成功", `共找到 ${payload.total || 0} 条记录。`);
  } catch {
    renderRows([]);
    setStatus("error", "请求失败", "网络异常或服务不可用，请稍后再试。");
  } finally {
    setBusy(false);
  }
});
