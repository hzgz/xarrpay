(function () {
  "use strict";

  var params = new URLSearchParams(window.location.search);
  var ticket = params.get("ticket");
  if (!ticket || window.__xarrTicketLoginStarted) return;
  window.__xarrTicketLoginStarted = true;
  window.history.replaceState({}, document.title, window.location.pathname + (window.location.hash || ""));

  fetch("/api/tickets/data?ticket=" + encodeURIComponent(ticket), {
    headers: { "Accept": "application/json" }
  }).then(function (response) {
    return response.json().then(function (body) {
      if (!response.ok || Number(body.code) !== 200) {
        throw new Error(body.message || "免密登录失败");
      }
      return body;
    });
  }).then(function (body) {
    var token = body.data && body.data.token;
    if (!token) throw new Error("免密登录票据未返回登录凭证");
    window.localStorage.setItem("token", token);
    window.location.replace("/user");
  }).catch(function (error) {
    var node = document.createElement("div");
    node.textContent = error && error.message ? error.message : "免密登录失败";
    node.style.cssText = "position:fixed;top:20px;right:20px;z-index:2147483647;padding:12px 16px;border:1px solid #fbc4ab;border-radius:4px;background:#fef0f0;color:#f56c6c;font:14px/1.5 sans-serif";
    var mount = function () { document.body.appendChild(node); };
    if (document.body) mount();
    else document.addEventListener("DOMContentLoaded", mount, { once: true });
  });
})();
