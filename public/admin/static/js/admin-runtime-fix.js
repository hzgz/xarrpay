(function () {
  "use strict";

  if (window.__xarrAdminRuntimeFix) return;
  window.__xarrAdminRuntimeFix = true;

  var activeMessages = {};

  function textFrom(value) {
    if (!value) return "";
    if (typeof value === "string") return value;
    if (value.response && value.response.data) return textFrom(value.response.data);
    if (value.message) return String(value.message);
    return "";
  }

  function showError(value) {
    var message = textFrom(value) || "请求失败，请检查后端返回";
    if (activeMessages[message]) return;
    activeMessages[message] = true;
    var node = document.createElement("div");
    node.textContent = message;
    node.style.cssText = "position:fixed;top:20px;right:20px;z-index:2147483647;max-width:420px;padding:12px 16px;border:1px solid #fbc4ab;border-radius:4px;background:#fef0f0;color:#f56c6c;font:14px/1.5 sans-serif;box-shadow:0 2px 12px rgba(0,0,0,.12)";
    document.body.appendChild(node);
    window.setTimeout(function () {
      node.remove();
      delete activeMessages[message];
    }, 4500);
  }

  window.addEventListener("unhandledrejection", function (event) {
    var message = textFrom(event.reason);
    if (/^Navigation cancelled from /.test(message)) {
      event.preventDefault();
      return;
    }
    if (event.reason && event.reason.response && Number(event.reason.response.status) >= 400) {
      event.preventDefault();
      showError(event.reason);
    }
  });
})();
