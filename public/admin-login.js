const loginForm = document.querySelector("#admin-login-form");
const keyInput = document.querySelector("#admin-login-key");
const submitButton = document.querySelector("#admin-login-submit");
const statusCard = document.querySelector("#admin-login-status");
const statusTitle = document.querySelector("#admin-login-status-title");
const statusMessage = document.querySelector("#admin-login-status-message");

function setLoginStatus(tone, title, message) {
  statusCard.className = `status-card status-${tone}`;
  statusTitle.textContent = title;
  statusMessage.textContent = message;
}

function setLoginBusy(busy) {
  submitButton.disabled = busy;
  submitButton.classList.toggle("is-processing", busy);
  submitButton.querySelector(".submit-button__label").textContent = busy ? "验证中" : "进入后台";
}

loginForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const accessKey = keyInput.value.trim();

  if (!accessKey) {
    setLoginStatus("error", "密钥不能为空", "请输入 .env 里的 RECORDS_ACCESS_KEY。");
    return;
  }

  setLoginBusy(true);
  setLoginStatus("pending", "正在验证", "正在校验后台访问密钥。");

  try {
    const response = await fetch("/api/admin/login", {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ accessKey })
    });
    const payload = await response.json().catch(() => ({ message: "验证失败。" }));

    if (!response.ok) {
      setLoginStatus("error", "验证失败", payload.message || "访问密钥错误。");
      return;
    }

    setLoginStatus("success", "验证成功", "正在进入管理后台。");
    window.location.href = "/admin.html";
  } catch {
    setLoginStatus("error", "请求失败", "网络异常或服务不可用，请稍后再试。");
  } finally {
    setLoginBusy(false);
  }
});
