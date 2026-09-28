#!/usr/bin/env python3
"""Visit previously failing admin pages and record network/console regressions."""

import importlib.util
import json
import os
import subprocess
import sys
import time


ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8088").rstrip("/")
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 9235

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

DEFAULT_ROUTES = [
    "/admin/staff/center",
    "/admin/staff/action-log",
    "/admin/work-order/cate",
    "/admin/work-order",
    "/admin/channel/gateway",
    "/admin/third-accounts/",
    "/admin/third-accounts/log",
    "/admin/domain-white",
    "/admin/black-data",
    "/admin/meal",
    "/admin/meal/order",
    "/admin/cards/groups",
    "/admin/cards",
    "/admin/proxy-pool",
    "/admin/setting/base",
    "/admin/setting/login",
    "/admin/setting/captcha",
    "/admin/setting/sms-channel",
    "/admin/setting/storage-channel",
    "/admin/setting/storage-file",
    "/admin/service-account-pool",
    "/admin/setting/notification",
    "/admin/setting/pay",
    "/admin/setting/proxy",
    "/admin/other/qrcode-templates",
    "/admin/other/template/index",
    "/admin/other/template/pay",
    "/admin/other/template/cashier",
    "/admin/other/template/user",
    "/admin/third-connect-chat",
    "/admin/tools/config-wizard",
    "/admin/logs",
    "/admin/app-store",
]


def load_admin_test():
    path = os.path.join(ROOT, "scripts", "browser-admin-test.py")
    spec = importlib.util.spec_from_file_location("browser_admin_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("无法加载浏览器回归模块")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main():
    module = load_admin_test()
    errors_path = os.path.join(ROOT, "var", "menu-scan-errors.json")
    pages = []
    if os.path.isfile(errors_path):
        with open(errors_path, "r", encoding="utf-8") as handle:
            pages = [item["url"] for item in json.load(handle) if item.get("url")]
    if not pages:
        pages = [BASE + route for route in DEFAULT_ROUTES]

    token = module.admin_login(BASE)
    browser = module.find_browser("")
    profile = os.path.join(ROOT, "var", "browser-admin-menu-profile")
    if os.path.isdir(profile):
        import shutil
        shutil.rmtree(profile)
    os.makedirs(profile, exist_ok=True)
    proc = subprocess.Popen([
        browser, "--headless=new", f"--remote-debugging-port={PORT}",
        "--remote-allow-origins=*", f"--user-data-dir={profile}",
        "--disable-gpu", "--disable-extensions", "--no-first-run",
        "--no-default-browser-check", "about:blank",
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    cdp = None
    results = []
    try:
        module.wait_for("browser CDP", 10, 0.25, lambda: module.http_json(f"http://127.0.0.1:{PORT}/json/version"))
        page = module.wait_for("browser page", 10, 0.25, lambda: next(
            (item for item in module.http_json(f"http://127.0.0.1:{PORT}/json") if item.get("type") == "page"),
            None,
        ))
        cdp = module.Cdp(page["webSocketDebuggerUrl"])
        cdp.call("Runtime.enable")
        cdp.call("Network.enable")
        cdp.call("Page.enable")
        cdp.call("Page.navigate", {"url": BASE + "/admin/login"})
        module.wait_for(
            "login shell",
            module.browser_wait_timeout(45),
            0.5,
            lambda: cdp.eval("document.readyState !== 'loading' && !!document.querySelector('#app')"),
        )
        module.wait_for("login route", module.browser_wait_timeout(30), 0.5, lambda: cdp.eval("location.pathname.endsWith('/admin/login')"))
        time.sleep(0.5)
        cdp.console_errors.clear()
        cdp.network_errors.clear()
        cdp.eval(
            "localStorage.setItem(" + json.dumps("token-admin") + ", " + json.dumps(token) + ");"
        )
        cdp.call("Page.navigate", {"url": BASE + "/admin"})
        module.wait_for(
            "admin dashboard authentication",
            module.browser_wait_timeout(45),
            0.5,
            lambda: cdp.eval(
                "document.readyState !== 'loading' && "
                "localStorage.getItem('token-admin') && "
                "!location.pathname.endsWith('/admin/login')"
            ),
        )
        time.sleep(1.0)

        for url in pages:
            cdp.network_errors.clear()
            cdp.console_errors.clear()
            cdp.requests.clear()
            cdp.call("Page.navigate", {"url": url})
            module.wait_for(
                "page shell",
                module.browser_wait_timeout(45),
                0.5,
                lambda: cdp.eval("document.readyState !== 'loading' && document.body.innerText.trim().length > 20"),
            )
            time.sleep(1.2)
            cdp.eval("document.body.innerText.slice(0, 20)")
            expected_unimplemented = [
                item
                for item in cdp.network_errors
                if "HTTP 501" in item and any(
                    marker in item
                    for marker in (
                        "/api/admin/staff/connect-channels",
                        "/api/admin/staff/passkey/list",
                    )
                )
            ]
            expected_console = []
            if expected_unimplemented:
                expected_console = [
                    item
                    for item in cdp.console_errors
                    if "Uncaught (in promise): AxiosError: Request failed with status code 501" in item
                ]
            results.append({
                "url": url,
                "network": [
                    item
                    for item in cdp.network_errors
                    if "/favicon" not in item and item not in expected_unimplemented
                ],
                "expected_unimplemented": expected_unimplemented,
                "console": [
                    item
                    for item in cdp.console_errors
                    if "favicon" not in item.lower() and item not in expected_console
                ],
                "expected_console": expected_console,
                "requests": cdp.requests[-20:],
            })
    finally:
        if cdp is not None:
            cdp.close()
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()

    output = os.path.join(ROOT, "var", "menu-scan-errors-after.json")
    with open(output, "w", encoding="utf-8") as handle:
        json.dump(results, handle, ensure_ascii=False, indent=2)
    failures = [item for item in results if item["network"] or item["console"]]
    print(json.dumps({"pages": len(results), "failures": len(failures), "output": output}, ensure_ascii=False))
    if failures:
        print(json.dumps(failures, ensure_ascii=False, indent=2))
        return 1
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"BROWSER ADMIN MENU TEST FAILED: {exc}", file=sys.stderr)
        raise SystemExit(1)
