const form = document.querySelector("#claim-form");
const emailInput = document.querySelector("#email");
const captchaInput = document.querySelector("#captcha");
const captchaImage = document.querySelector("#captcha-image");
const captchaRefreshButton = document.querySelector("#captcha-refresh");
const submitButton = document.querySelector("#submit-button");
const statusCard = document.querySelector("#status-card");
const statusTitle = document.querySelector("#status-title");
const statusMessage = document.querySelector("#status-message");
const amountNodes = document.querySelectorAll("[data-claim-amount]");
const submitButtonLabel = document.querySelector("[data-submit-label]");
let captchaToken = "";

function setStatus(tone, title, message) {
  statusCard.className = `status-card status-${tone}`;
  statusTitle.textContent = title;
  statusMessage.textContent = message;
}

function setBusy(busy) {
  submitButton.disabled = busy;
  submitButton.classList.toggle("is-processing", busy);
  if (captchaRefreshButton) {
    captchaRefreshButton.disabled = busy;
  }
  if (submitButtonLabel) {
    submitButtonLabel.textContent = busy ? "信号追踪中" : "领取额度";
  }
}

async function loadCaptcha() {
  if (!captchaImage) {
    return;
  }

  const response = await fetch("/api/captcha", { cache: "no-store" });
  const payload = await response.json().catch(() => ({
    message: "验证码加载失败。"
  }));

  if (!response.ok || !payload.token || !payload.image) {
    captchaToken = "";
    throw new Error(payload.message || "验证码加载失败。");
  }

  captchaToken = payload.token;
  captchaImage.src = payload.image;
}

async function loadConfig() {
  try {
    const response = await fetch("/api/config", { cache: "no-store" });
    if (!response.ok) {
      return;
    }
    const config = await response.json();
    const amount = String(config.claimAmount || 10);
    amountNodes.forEach((node) => {
      node.textContent = amount;
    });
  } catch {
    // Keep the default copy if config loading fails.
  }
}

captchaRefreshButton?.addEventListener("click", async () => {
  try {
    captchaInput.value = "";
    await loadCaptcha();
    captchaInput.focus();
  } catch {
    setStatus("error", "验证码加载失败", "请稍后重试或刷新页面。");
  }
});

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const email = emailInput.value.trim();
  const captchaCode = captchaInput.value.trim();

  if (!email) {
    setStatus("error", "邮箱不能为空", "请输入要领取额度的注册邮箱。");
    return;
  }

  if (!captchaCode || !captchaToken) {
    setStatus("error", "验证码不能为空", "请输入验证码后再领取。");
    return;
  }

  setBusy(true);
  setStatus("pending", "正在核验", "正在检查用户是否存在，并尝试发放额度。");

  try {
    const response = await fetch("/api/claim", {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        email,
        captchaToken,
        captchaCode
      })
    });

    const payload = await response.json().catch(() => ({
      message: "服务返回异常。"
    }));

    captchaInput.value = "";
    await loadCaptcha();

    if (!response.ok) {
      const tone = payload.error === "already_claimed" || payload.error === "claim_pending"
        ? "warning"
        : "error";
      setStatus(tone, "领取失败", payload.message || "处理失败，请稍后再试。");
      return;
    }

    setStatus(
      "success",
      "领取成功",
      payload.message || `已为 ${email} 发放额度。`
    );
    form.reset();
    emailInput.focus();
  } catch {
    captchaInput.value = "";
    try {
      await loadCaptcha();
    } catch {
      // Ignore secondary captcha refresh errors here.
    }
    setStatus("error", "请求失败", "网络异常或服务不可用，请稍后再试。");
  } finally {
    setBusy(false);
  }
});

loadConfig();
loadCaptcha().catch(() => {
  setStatus("error", "验证码加载失败", "请刷新页面后重试。");
});
