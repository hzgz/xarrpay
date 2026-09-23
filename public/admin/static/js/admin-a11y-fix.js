(function () {
  "use strict";

  if (window.__xarrAdminA11yFix) return;
  window.__xarrAdminA11yFix = true;

  var sequence = 0;
  var scheduled = false;
  var nativeFieldSelector = 'input:not([type="hidden"]), select, textarea, button';
  var customFieldSelector = '[contenteditable="true"], [role="combobox"], [role="textbox"], [role="checkbox"], [role="switch"]';
  var fieldSelector = nativeFieldSelector + "," + customFieldSelector;

  function isField(element) {
    if (!element || element.nodeType !== 1) return false;
    if (element.matches('input[type="hidden"]')) return false;
    return element.matches(fieldSelector);
  }

  function isNativeField(element) {
    return Boolean(element && element.matches(nativeFieldSelector));
  }

  function makeId(seed, element) {
    var value = String(seed || "field")
      .trim()
      .replace(/[^a-zA-Z0-9_-]+/g, "-")
      .replace(/^-+|-+$/g, "");

    if (!value) value = "field";
    if (!/^[a-zA-Z_]/.test(value)) value = "xarr-" + value;

    var id = value;
    while (true) {
      var existing = document.getElementById(id);
      if (!existing || existing === element) return id;
      id = value + "-" + (++sequence);
    }
  }

  function firstField(container) {
    if (!container) return null;
    var fields = container.querySelectorAll(nativeFieldSelector);
    for (var i = 0; i < fields.length; i += 1) {
      var field = fields[i];
      if (field.offsetParent !== null || field.getAttribute("aria-hidden") !== "true") {
        return field;
      }
    }

    fields = container.querySelectorAll(customFieldSelector);
    for (var j = 0; j < fields.length; j += 1) {
      var customField = fields[j];
      if (customField.offsetParent !== null || customField.getAttribute("aria-hidden") !== "true") {
        return customField;
      }
    }

    return fields[0] || null;
  }

  function fieldLabel(field, label) {
    if (label) {
      var visibleText = label.textContent.replace(/\s+/g, " ").trim();
      if (visibleText) return visibleText;
    }
    return field.getAttribute("aria-label") ||
      field.getAttribute("placeholder") ||
      field.getAttribute("title") ||
      field.getAttribute("name") ||
      "表单字段";
  }

  function normalizeField(field, label, seed) {
    if (!isField(field)) return;

    var currentId = field.getAttribute("id");
    var labelFor = label && label.getAttribute("for");
    var matching = labelFor && document.getElementById(labelFor);
    var id = currentId;

    if (!id || (matching && matching !== field)) {
      id = makeId(labelFor || field.getAttribute("name") || seed || "xarr-field", field);
      field.setAttribute("id", id);
    }

    if (isNativeField(field)) {
      if (label && labelFor !== id) {
        label.setAttribute("for", id);
      }
    } else if (label) {
      if (!label.id) label.id = makeId("xarr-label", label);
      field.setAttribute("aria-labelledby", label.id);
      var labelTarget = labelFor && document.getElementById(labelFor);
      if (!labelTarget || !isNativeField(labelTarget)) {
        label.removeAttribute("for");
      }
    } else if (!field.getAttribute("aria-label") && !field.getAttribute("aria-labelledby")) {
      field.setAttribute("aria-label", fieldLabel(field, null));
    }
  }

  function normalizeFormItem(item) {
    var label = item.querySelector(".el-form-item__label");
    var field = firstField(item);
    if (!field) return;

    var seed = label && label.getAttribute("for");
    normalizeField(field, label, seed || "xarr-field");

    var fields = item.querySelectorAll(fieldSelector);
    for (var i = 1; i < fields.length; i += 1) {
      var nestedLabel = fields[i].closest("label");
      normalizeField(fields[i], nestedLabel, seed || "xarr-field");
    }
  }

  function normalize(root) {
    if (!root || !root.querySelectorAll) return;

    var items = root.querySelectorAll(".el-form-item");
    for (var i = 0; i < items.length; i += 1) {
      normalizeFormItem(items[i]);
    }

    var fields = root.querySelectorAll(fieldSelector);
    for (var j = 0; j < fields.length; j += 1) {
      var field = fields[j];
      if (!field.id) {
        var parentItem = field.closest(".el-form-item");
        var parentLabel = parentItem && parentItem.querySelector(".el-form-item__label");
        normalizeField(field, parentLabel && parentLabel.textContent.trim() ? parentLabel : null, "xarr-field");
      }
    }
  }

  function schedule() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(function () {
      scheduled = false;
      normalize(document);
    }, 0);
  }

  function start() {
    normalize(document);
    if (!document.documentElement || !window.MutationObserver) return;

    var observer = new MutationObserver(schedule);
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
  } else {
    start();
  }
})();
