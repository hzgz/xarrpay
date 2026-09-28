(function () {
  "use strict";

  var routes = [
    [/\/admin\/staff\/(connect-channels|passkey\/list)/, "外部账号与通行密钥功能暂未上线"]
  ];
  var shown = {};

  function resolve(value) {
    var text = String(value || "");
    for (var i = 0; i < routes.length; i += 1) {
      if (routes[i][0].test(text)) return routes[i][1];
    }
    return "";
  }

  function errorMessage(error) {
    var response = error && error.response;
    var config = response && response.config;
    return resolve(config && config.url) || resolve(error && error.message);
  }

  function show(message) {
    if (!message || shown[message]) return;
    shown[message] = true;

    var node = document.createElement("div");
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
      "box-shadow:0 2px 12px rgba(0,0,0,.1)"
    ].join(";");
    document.body.appendChild(node);
    window.setTimeout(function () {
      node.remove();
      delete shown[message];
    }, 3500);
  }

  window.addEventListener("unhandledrejection", function (event) {
    var reason = event.reason;
    if (reason && /^Navigation cancelled from /.test(String(reason.message || reason))) {
      event.preventDefault();
      return;
    }
    var response = reason && reason.response;
    if (!response || Number(response.status) !== 501) return;

    var message = errorMessage(reason);
    if (!message) return;
    event.preventDefault();
    show(message);
  });

  var originalError = console.error;
  console.error = function () {
    var args = Array.prototype.slice.call(arguments);
    var text = args.map(function (item) {
      if (item && item.response) {
        return item.response.config && item.response.config.url;
      }
      return item && item.message ? item.message : item;
    }).join(" ");
    var message = resolve(text);
    if (message && text.indexOf("status code 501") !== -1) {
      show(message);
      return;
    }
    originalError.apply(console, args);
  };
})();
