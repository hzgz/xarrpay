#!/usr/bin/env python3
import argparse
import http.cookiejar
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request

import websocket


if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")


def find_browser(explicit):
    candidates = []
    if explicit:
        candidates.append(explicit)
    candidates.extend([
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    ])
    for path in candidates:
        if path and os.path.isfile(path):
            return path
    raise RuntimeError("Cannot find Edge or Chrome executable")


def http_json(url):
    with urllib.request.urlopen(url, timeout=5) as response:
        return json.loads(response.read().decode("utf-8"))


def wait_for(label, timeout, interval, fn):
    deadline = time.time() + timeout
    last = None
    while time.time() < deadline:
        try:
            last = fn()
            if last:
                return last
        except Exception as exc:
            last = exc
        time.sleep(interval)
    raise RuntimeError(f"Timed out waiting for {label}: {last}")


def browser_wait_timeout(default):
    value = os.environ.get("XARR_BROWSER_WAIT_SECONDS", "").strip()
    if not value:
        return default
    try:
        return max(default, int(value))
    except ValueError:
        return default


def read_session_code(session_id):
    remote_host = os.environ.get("XARR_REMOTE_SSH_HOST", "").strip()
    if remote_host:
        remote_user = os.environ.get("XARR_REMOTE_SSH_USER", "").strip()
        remote_password = os.environ.get("XARR_REMOTE_SSH_PASSWORD")
        remote_dir = os.environ.get("XARR_REMOTE_SESSION_DIR", "/tmp").strip() or "/tmp"
        if not remote_user or remote_password is None:
            raise RuntimeError(
                "已启用远端 session 模式，但 XARR_REMOTE_SSH_USER 或 "
                "XARR_REMOTE_SSH_PASSWORD 未设置"
            )
        if re.fullmatch(r"[A-Za-z0-9_-]+", session_id) is None:
            raise RuntimeError("远端 session ID 格式非法")
        try:
            import paramiko
        except ImportError as exc:
            raise RuntimeError("远端 session 模式需要安装 Python 包 paramiko") from exc
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(
            remote_host,
            username=remote_user,
            password=remote_password,
            timeout=5,
            banner_timeout=5,
            auth_timeout=5,
        )
        try:
            with client.open_sftp().open(f"{remote_dir}/sess_{session_id}", "rb") as handle:
                contents = handle.read().decode("utf-8", "ignore")
        except FileNotFoundError:
            return None
        finally:
            client.close()
        match = re.search(r's:4:"code";s:\d+:"(\d{4})"', contents)
        return match.group(1) if match else None

    candidates = [tempfile.gettempdir(), r"C:\Windows\Temp"]
    save_path = os.environ.get("TMP")
    if save_path:
        candidates.insert(0, save_path)
    for directory in candidates:
        path = os.path.join(directory, "sess_" + session_id)
        if not os.path.isfile(path):
            continue
        with open(path, "rb") as handle:
            contents = handle.read().decode("utf-8", "ignore")
        match = re.search(r's:4:"code";s:\d+:"(\d{4})"', contents)
        if match:
            return match.group(1)
    return None


def admin_login(base):
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    def request(path, data=None):
        payload = None
        headers = {"Accept": "application/json"}
        if data is not None:
            payload = urllib.parse.urlencode(data).encode("utf-8")
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        req = urllib.request.Request(base + path, data=payload, headers=headers)
        with opener.open(req, timeout=10) as response:
            return json.loads(response.read().decode("utf-8"))

    captcha = request("/api/admin/login/captcha")
    if int(captcha.get("code") or 0) != 200:
        raise RuntimeError("captcha failed: " + json.dumps(captcha, ensure_ascii=False))
    session_id = next((cookie.value for cookie in jar if cookie.name == "xarr_php"), "")
    code = wait_for("captcha session code", 10, 0.25, lambda: read_session_code(session_id))
    login = request("/api/admin/login", {
        "username": "admin",
        "password": "123456",
        "captcha_id": captcha.get("data", {}).get("captcha_id", ""),
        "captcha_code": code,
    })
    if int(login.get("code") or 0) != 200:
        raise RuntimeError("admin login failed: " + json.dumps(login, ensure_ascii=False))
    token = login.get("data", {}).get("token", "")
    if not token:
        raise RuntimeError("admin token missing")
    return token


class Cdp:
    def __init__(self, debugger_url):
        self.ws = websocket.create_connection(debugger_url, timeout=5, suppress_origin=True)
        self.seq = 0
        self.console_errors = []
        self.network_errors = []
        self.requests = []

    def close(self):
        self.ws.close()

    def handle_event(self, message):
        method = message.get("method")
        params = message.get("params") or {}
        if method == "Runtime.exceptionThrown":
            details = params.get("exceptionDetails") or {}
            text = details.get("text") or "Runtime exception"
            exception = details.get("exception") or {}
            description = exception.get("description") or exception.get("value") or ""
            self.console_errors.append(f"{text}: {description}".strip())
        elif method == "Runtime.consoleAPICalled" and params.get("type") in {"error", "assert"}:
            args = params.get("args") or []
            text = " ".join(str(arg.get("value") or arg.get("description") or arg.get("type") or "") for arg in args)
            self.console_errors.append(text.strip() or "console error")
        elif method == "Network.requestWillBeSent":
            request = params.get("request") or {}
            url = request.get("url") or ""
            if "/api/" in url:
                self.requests.append(url)
        elif method == "Network.responseReceived":
            response = params.get("response") or {}
            status = int(response.get("status") or 0)
            url = response.get("url") or ""
            if status >= 400:
                self.network_errors.append(f"HTTP {status} {url}")
        elif method == "Network.loadingFailed":
            url = params.get("requestId") or ""
            error = params.get("errorText") or ""
            self.network_errors.append(f"LOAD_FAILED {url} {error}".strip())

    def call(self, method, params=None):
        self.seq += 1
        ident = self.seq
        self.ws.send(json.dumps({"id": ident, "method": method, "params": params or {}}))
        while True:
            message = json.loads(self.ws.recv())
            if message.get("id") == ident:
                if "error" in message:
                    raise RuntimeError(f"CDP {method} failed: {message['error']}")
                return message.get("result") or {}
            self.handle_event(message)

    def eval(self, expression, await_promise=False):
        result = self.call("Runtime.evaluate", {
            "expression": expression,
            "awaitPromise": await_promise,
            "returnByValue": True,
        })
        if "exceptionDetails" in result:
            raise RuntimeError(json.dumps(result["exceptionDetails"], ensure_ascii=False))
        value = result.get("result") or {}
        return value.get("value")

    def preload_local_storage(self, key, value):
        self.call("Page.addScriptToEvaluateOnNewDocument", {
            "source": "localStorage.setItem(" + json.dumps(key) + ", " + json.dumps(value) + ");",
        })


def main():
    parser = argparse.ArgumentParser(description="Browser regression for admin dashboard")
    parser.add_argument("base", nargs="?", default="http://127.0.0.1:8088")
    parser.add_argument("--browser", default="")
    parser.add_argument("--port", type=int, default=9234)
    parser.add_argument("--profile", default=os.path.join("var", "browser-admin-profile"))
    args = parser.parse_args()

    base = args.base.rstrip("/")
    token = admin_login(base)
    browser = find_browser(args.browser)
    profile = os.path.abspath(args.profile)
    if os.path.isdir(profile):
        shutil.rmtree(profile)
    os.makedirs(profile, exist_ok=True)

    proc = subprocess.Popen([
        browser,
        "--headless=new",
        f"--remote-debugging-port={args.port}",
        "--remote-allow-origins=*",
        f"--user-data-dir={profile}",
        "--disable-gpu",
        "--disable-extensions",
        "--no-first-run",
        "--no-default-browser-check",
        "about:blank",
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    cdp = None
    try:
        wait_for("browser CDP", 10, 0.25, lambda: http_json(f"http://127.0.0.1:{args.port}/json/version"))
        page = wait_for("browser page", 10, 0.25, lambda: next((item for item in http_json(f"http://127.0.0.1:{args.port}/json") if item.get("type") == "page"), None))
        cdp = Cdp(page["webSocketDebuggerUrl"])
        cdp.call("Runtime.enable")
        cdp.call("Network.enable")
        cdp.call("Page.enable")
        cdp.call("Page.navigate", {"url": base + "/admin/login"})
        wait_for(
            "admin app bootstrap",
            browser_wait_timeout(45),
            0.5,
            lambda: cdp.eval("document.readyState !== 'loading' && !!document.querySelector('#app')"),
        )
        wait_for("admin login route", browser_wait_timeout(30), 0.5, lambda: cdp.eval("location.pathname.endsWith('/admin/login')"))
        time.sleep(0.5)
        cdp.console_errors.clear()
        cdp.network_errors.clear()
        cdp.eval(
            "localStorage.setItem(" + json.dumps("token-admin") + ", " + json.dumps(token) + ");"
        )
        cdp.call("Page.navigate", {"url": base + "/admin"})
        wait_for(
            "admin dashboard shell",
            browser_wait_timeout(45),
            0.5,
            lambda: cdp.eval("document.readyState !== 'loading' && document.body.innerText.trim().length > 20"),
        )
        time.sleep(2)
        state = cdp.eval("""
(() => ({
  url: location.href,
  title: document.title,
  tokenLength: (localStorage.getItem('token-admin') || '').length,
  text: document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 600),
  menuCount: document.querySelectorAll('.el-menu-item').length,
  cardCount: document.querySelectorAll('.el-card').length,
  dialogText: Array.from(document.querySelectorAll('.el-dialog')).map(el => el.innerText.trim()).join(' | ').slice(0, 500),
}))()
""")
        fatal_network = [item for item in cdp.network_errors if "/favicon" not in item]
        fatal_console = [item for item in cdp.console_errors if "favicon" not in item.lower()]
        if fatal_network or fatal_console:
            raise RuntimeError(json.dumps({
                "state": state,
                "network_errors": fatal_network[:20],
                "console_errors": fatal_console[:10],
                "api_requests": cdp.requests[-30:],
            }, ensure_ascii=False))
        if int(state.get("menuCount") or 0) == 0 or state.get("url", "").endswith("/admin/login"):
            raise RuntimeError("admin shell is blank: " + json.dumps(state, ensure_ascii=False))
        print(json.dumps({
            "ok": True,
            "url": state.get("url"),
            "title": state.get("title"),
            "menu_count": state.get("menuCount"),
            "card_count": state.get("cardCount"),
            "api_requests": cdp.requests[-20:],
        }, ensure_ascii=False))
    finally:
        if cdp is not None:
            cdp.close()
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"BROWSER ADMIN TEST FAILED: {exc}", file=sys.stderr)
        sys.exit(1)
