(function () {
  "use strict";

  if (window.__xarrSiteBranding) return;
  window.__xarrSiteBranding = true;

  var config = null;
  var lastAppliedTitle = "";
  var scheduled = false;

  function text(value) {
    return value == null ? "" : String(value).trim();
  }

  function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  function setMeta(name, value) {
    var node = document.querySelector('meta[name="' + name + '"]');
    if (!node) {
      node = document.createElement("meta");
      node.setAttribute("name", name);
      document.head.appendChild(node);
    }
    node.setAttribute("content", text(value));
  }

  function pageBaseTitle() {
    var value = text(document.title);
    if (lastAppliedTitle && value === lastAppliedTitle) return "";

    if (config && config.web_title) {
      value = value.replace(new RegExp("\\s*-\\s*" + escapeRegExp(config.web_title) + "\\s*$"), "");
    }
    value = value.replace(/\s*-\s*后台管理系统\s*$/, "").trim();
    if (value === "{{title}}") {
      return "";
    }
    return value;
  }

  function apply() {
    if (!config) return;

    var siteTitle = text(config.web_title);
    var indexTitle = text(config.web_index_title) || siteTitle;
    var path = window.location.pathname || "/";

    document.querySelectorAll("[data-site-name]").forEach(function (node) {
      node.textContent = siteTitle;
    });
    setMeta("keywords", config.web_keywords);
    setMeta("description", config.web_description);

    var baseTitle = pageBaseTitle();
    var nextTitle = "";
    if (path === "/" || path === "") {
      nextTitle = indexTitle;
    } else if (path.indexOf("/admin") === 0) {
      nextTitle = baseTitle && baseTitle !== siteTitle ? baseTitle + (siteTitle ? " - " + siteTitle : "") : siteTitle;
    } else {
      nextTitle = baseTitle && baseTitle !== siteTitle ? baseTitle + (siteTitle ? " - " + siteTitle : "") : siteTitle;
    }

    if (document.title !== nextTitle) {
      lastAppliedTitle = nextTitle;
      document.title = nextTitle;
    }
  }

  function schedule() {
    if (scheduled) return;
    scheduled = true;
    window.setTimeout(function () {
      scheduled = false;
      apply();
    }, 0);
  }

  function load() {
    fetch("/api/config", { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (response) {
        return response.json();
      })
      .then(function (body) {
        if (!body || Number(body.code) !== 200 || !body.data) return;
        config = body.data;
        apply();
      })
      .catch(function () {});
  }

  if (window.MutationObserver && document.head) {
    new MutationObserver(schedule).observe(document.head, {
      childList: true,
      subtree: true,
      characterData: true
    });
  }

  load();
})();
