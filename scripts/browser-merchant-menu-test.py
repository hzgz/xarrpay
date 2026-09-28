#!/usr/bin/env python3
"""Visit merchant-center routes and record browser/API regressions."""

import importlib.util
import json
import os
import shutil
import subprocess
import sys
import time


ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8088").rstrip("/")
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 9243

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

ROUTES = [
    "/user",
    "/user/order",
    "/user/income",
    "/user/statistics",
    "/user/log/notify",
    "/user/api",
    "/user/info",
    "/user/resetpwd",
    "/user/log/login",
    "/user/meal",
    "/user/recharge",
    "/user/log/balance",
    "/user/invite",
    "/user/channel/account",
    "/user/polling",
    "/user/cashier",
    "/user/setting/pay",
    "/user/work-order",
    "/user/work-order/submit",
    "/user/work-order/detail",
    "/user/invite",
    "/user/pay-code",
    "/user/external/1",
    "/user/domain-white",
    "/user/mcp",
    "/user/verification",
    "/user/withdraw",
    "/user/withdraw/account",
]


def load_login_module():
    path = os.path.join(ROOT, "scripts", "browser-login-test.py")
    spec = importlib.util.spec_from_file_location("browser_login_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("无法加载商户登录回归模块")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def login(module, cdp):
    cdp.call("Page.navigate", {"url": BASE + "/login"})
    module.wait_for(
        "login form",
        module.browser_wait_timeout(45),
        0.5,
        lambda: cdp.eval(
            "document.readyState !== 'loading' && "
            "!!document.querySelector('input[placeholder=\"用户名/邮件\"]') && "
            "!!document.querySelector('input[placeholder=\"请输入验证码\"]')"
        ),
    )
    session_id = module.wait_for(
        "xarr_php cookie",
        10,
        0.25,
        lambda: next(
            (
                c["value"]
                for c in cdp.call("Network.getAllCookies").get("cookies", [])
                if c.get("name") == "xarr_php"
            ),
            None,
        ),
    )
    captcha_code = module.wait_for(
        "captcha session code",
        10,
        0.25,
        lambda: module.read_session_code(session_id),
    )
    for selector, value in [
        ('input[placeholder="用户名/邮件"]', "coco"),
        ('input[type="password"]', "123456"),
        ('input[placeholder="请输入验证码"]', captcha_code),
    ]:
        actual = module.set_input(cdp, selector, value)
        if actual != value:
            raise RuntimeError(f"无法填写 {selector}: {actual!r}")
    cdp.eval(
        """
(() => {
  const agreement = document.querySelector('.agree-ys');
  if (agreement) agreement.click();
  const checkbox = document.querySelector('input[type="checkbox"]');
  if (checkbox && !checkbox.checked) {
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'checked').set;
    setter.call(checkbox, true);
    checkbox.dispatchEvent(new Event('change', {bubbles: true}));
    checkbox.dispatchEvent(new Event('input', {bubbles: true}));
  }
  const button = Array.from(document.querySelectorAll('button'))
    .find(el => el.textContent.trim() === '登录');
  if (!button) throw new Error('login button not found');
  button.click();
  return true;
})()
"""
    )
    for _ in range(module.browser_wait_timeout(30) * 2):
        token_value = cdp.eval("localStorage.getItem('token') || ''")
        if token_value and not cdp.eval("location.pathname.endsWith('/login')"):
            break
        module.click_text_button(cdp, "同意并继续")
        time.sleep(0.25)
    token = module.wait_for(
        "merchant token",
        module.browser_wait_timeout(30),
        0.5,
        lambda: cdp.eval("localStorage.getItem('token') || ''"),
    )
    if not token:
        raise RuntimeError("商户 token 为空")


def main():
    module = load_login_module()
    browser = module.find_browser("")
    profile = os.path.join(ROOT, "var", "browser-merchant-menu-profile")
    if os.path.isdir(profile):
        shutil.rmtree(profile)
    os.makedirs(profile, exist_ok=True)
    proc = subprocess.Popen(
        [
            browser,
            "--headless=new",
            f"--remote-debugging-port={PORT}",
            "--remote-allow-origins=*",
            f"--user-data-dir={profile}",
            "--disable-gpu",
            "--disable-extensions",
            "--no-first-run",
            "--no-default-browser-check",
            "about:blank",
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    cdp = None
    results = []
    try:
        module.wait_for(
            "browser CDP",
            10,
            0.25,
            lambda: module.http_json(f"http://127.0.0.1:{PORT}/json/version"),
        )
        page = module.wait_for(
            "browser page",
            10,
            0.25,
            lambda: next(
                (
                    item
                    for item in module.http_json(f"http://127.0.0.1:{PORT}/json")
                    if item.get("type") == "page"
                ),
                None,
            ),
        )
        cdp = module.Cdp(page["webSocketDebuggerUrl"])
        cdp.call("Runtime.enable")
        cdp.call("Network.enable")
        cdp.call("Page.enable")
        login(module, cdp)
        for route in ROUTES:
            cdp.console_errors.clear()
            cdp.network_errors.clear()
            cdp.call("Page.navigate", {"url": BASE + route})
            module.wait_for(
                f"page shell {route}",
                module.browser_wait_timeout(45),
                0.5,
                lambda: cdp.eval(
                    "document.readyState !== 'loading' && "
                    "document.body.innerText.trim().length > 20"
                ),
            )
            time.sleep(1.0)
            expected_unimplemented = [
                item
                for item in cdp.network_errors
                if "HTTP 501" in item and any(
                    marker in item
                    for marker in (
                        "/api/user/connect/channels",
                        "/api/user/connect/list",
                        "/api/user/passkey/list",
                        "/api/invite/rebate_record",
                    )
                )
            ]
            result = {
                    "route": route,
                    "url": cdp.eval("location.href"),
                    "network": [
                        item
                        for item in cdp.network_errors
                        if "/favicon" not in item and item not in expected_unimplemented
                    ],
                    "expected_unimplemented": expected_unimplemented,
                    "console": [
                        item
                        for item in cdp.console_errors
                        if "favicon" not in item.lower()
                    ],
                    "text": cdp.eval(
                        "document.body.innerText.replace(/\\s+/g, ' ').trim().slice(0, 240)"
                    ),
                }
            result["content_issues"] = []
            if route == "/user/invite" and "undefined" in result["text"].lower():
                result["content_issues"].append("invite page contains undefined")
            if route == "/user/log/balance" and "nan" in result["text"].lower():
                result["content_issues"].append("balance page contains NaN")
            if route == "/user/channel/account" and "nan" in result["text"].lower():
                result["content_issues"].append("channel account page contains NaN")
            for marker in ("404 not found", "we're sorry", "please enable", "loading"):
                if marker in result["text"].lower():
                    result["content_issues"].append(f"merchant page contains English prompt: {marker}")
            results.append(result)
    finally:
        if cdp is not None:
            cdp.close()
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()

    output = os.path.join(ROOT, "var", "merchant-menu-scan.json")
    with open(output, "w", encoding="utf-8") as handle:
        json.dump(results, handle, ensure_ascii=False, indent=2)
    failures = [
        item
        for item in results
        if item["network"] or item["console"] or item["content_issues"] or item["url"].rstrip("/") == BASE + "/login"
    ]
    print(
        json.dumps(
            {"pages": len(results), "failures": len(failures), "output": output},
            ensure_ascii=False,
        )
    )
    if failures:
        print(json.dumps(failures, ensure_ascii=False, indent=2))
        return 1
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"BROWSER MERCHANT MENU TEST FAILED: {exc}", file=sys.stderr)
        raise SystemExit(1)
