(() => {
  const messages = [
    [/\/user\/connect\/(channels|list)/, "连接通道功能暂未上线"],
    [/\/user\/passkey\/list/, "通行密钥功能暂未上线"],
  ];
  const shown = new Set();

  function match(value) {
    const text = String(value || "");
    return messages.find(([pattern]) => pattern.test(text));
  }

  function messageFor(error) {
    const response = error && error.response;
    const responseMessage = response && response.data && response.data.message;
    if (responseMessage) {
      return String(responseMessage);
    }
    const url = response && response.config && response.config.url;
    const result = match(url) || match(error && error.message);
    return result ? result[1] : "";
  }

  function show(message) {
    if (!message || shown.has(message)) {
      return;
    }
    shown.add(message);
    const node = document.createElement("div");
    node.textContent = message;
    node.style.cssText = [
      "position:fixed",
      "top:20px",
      "right:20px",
      "z-index:2147483647",
      "max-width:360px",
      "padding:12px 16px",
      "border:1px solid #d9ecff",
      "border-radius:4px",
      "background:#ecf5ff",
      "color:#409eff",
      "font:14px/1.5 sans-serif",
      "box-shadow:0 2px 12px rgba(0,0,0,.1)",
    ].join(";");
    document.body.appendChild(node);
    window.setTimeout(() => {
      node.remove();
      shown.delete(message);
    }, 3500);
  }

  window.addEventListener("unhandledrejection", (event) => {
    const error = event.reason;
    const errorText = error && String(error.message || error);
    if (/^Navigation cancelled from /.test(errorText)) {
      event.preventDefault();
      return;
    }
    const response = error && error.response;
    if (!response || Number(response.status) < 400) {
      return;
    }
    const message = messageFor(error) || "请求失败，请检查后端返回";
    if (!message) {
      return;
    }
    event.preventDefault();
    show(message);
  });

  const originalError = console.error;
  console.error = (...args) => {
    const responseError = args.find((item) => item && item.response);
    if (responseError && responseError.response && Number(responseError.response.status) >= 400) {
      show(messageFor(responseError) || "请求失败，请检查后端返回");
      return;
    }
    const text = args.map((item) => {
      if (item && item.response) {
        return item.response.config && item.response.config.url;
      }
      return item && item.message ? item.message : item;
    }).join(" ");
    const result = match(text);
    if (result) {
      show(result[1]);
      return;
    }
    originalError.apply(console, args);
  };
})();
