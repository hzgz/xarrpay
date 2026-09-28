#!/usr/bin/env python3
import argparse
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


class Cdp:
    def __init__(self, debugger_url):
        self.ws = websocket.create_connection(debugger_url, timeout=5, suppress_origin=True)
        self.seq = 0
        self.console_errors = []
        self.network_errors = []

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
        elif method == "Network.responseReceived":
            response = params.get("response") or {}
            status = int(response.get("status") or 0)
            url = response.get("url") or ""
            if status >= 400:
                self.network_errors.append(f"HTTP {status} {url}")

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
        value = result.get("result") or {}
        if "exceptionDetails" in result:
            raise RuntimeError(json.dumps(result["exceptionDetails"], ensure_ascii=False))
        return value.get("value")


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


def set_input(cdp, selector, value):
    return cdp.eval(f"""
(() => {{
  const input = document.querySelector({json.dumps(selector)});
  if (!input) return null;
  const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
  setter.call(input, {json.dumps(value)});
  input.dispatchEvent(new Event('input', {{bubbles: true}}));
  input.dispatchEvent(new Event('change', {{bubbles: true}}));
  input.dispatchEvent(new Event('blur', {{bubbles: true}}));
  return input.value;
}})()
""")


def click_text_button(cdp, text):
    return cdp.eval(f"""
(() => {{
  const target = Array.from(document.querySelectorAll('button'))
    .find(el => el.textContent.trim() === {json.dumps(text)});
  if (!target) return false;
  target.click();
  return true;
}})()
""")


def diagnostic_state(cdp):
    try:
        return cdp.eval("""
(() => ({
  url: location.href,
  token: localStorage.getItem('token') || '',
  inputs: Array.from(document.querySelectorAll('input')).map(input => ({
    placeholder: input.getAttribute('placeholder') || '',
    type: input.type || '',
    checked: !!input.checked,
    value: input.type === 'password' ? (input.value ? '<password-present>' : '') : input.value,
  })),
  buttons: Array.from(document.querySelectorAll('button')).map(button => button.textContent.trim()).filter(Boolean),
  messages: Array.from(document.querySelectorAll('.el-message, .el-message-box, .el-form-item__error'))
    .map(el => el.textContent.trim()).filter(Boolean),
}))()
""")
    except Exception as exc:
        return {"diagnostic_error": str(exc)}


def main():
    parser = argparse.ArgumentParser(description="Browser regression for merchant login")
    parser.add_argument("base", nargs="?", default="http://127.0.0.1:8088")
    parser.add_argument("--browser", default="")
    parser.add_argument("--port", type=int, default=9233)
    parser.add_argument("--profile", default=os.path.join("var", "browser-login-profile"))
    args = parser.parse_args()

    base = args.base.rstrip("/")
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
        cdp.call("Page.navigate", {"url": base + "/login"})
        wait_for(
            "login form",
            browser_wait_timeout(45),
            0.5,
            lambda: cdp.eval(
                "document.readyState !== 'loading' && "
                "!!document.querySelector('input[placeholder=\"用户名/邮件\"]') && "
                "!!document.querySelector('input[placeholder=\"请输入验证码\"]')"
            ),
        )

        session_id = wait_for("xarr_php cookie", 10, 0.25, lambda: next((c["value"] for c in cdp.call("Network.getAllCookies").get("cookies", []) if c.get("name") == "xarr_php"), None))
        captcha_code = wait_for("captcha session code", 10, 0.25, lambda: read_session_code(session_id))

        for selector, value in [
            ('input[placeholder="用户名/邮件"]', 'coco'),
            ('input[type="password"]', '123456'),
            ('input[placeholder="请输入验证码"]', captcha_code),
        ]:
            actual = set_input(cdp, selector, value)
            if actual != value:
                raise RuntimeError(f"Cannot set input {selector}: {actual!r}")

        cdp.eval("""
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
  const button = Array.from(document.querySelectorAll('button')).find(el => el.textContent.trim() === '登录');
  if (!button) throw new Error('login button not found');
  button.click();
  return true;
})()
""")

        for _ in range(browser_wait_timeout(30) * 2):
            token_value = cdp.eval("localStorage.getItem('token') || ''")
            if token_value and not cdp.eval("location.pathname.endsWith('/login')"):
                break
            click_text_button(cdp, "同意并继续")
            time.sleep(0.25)

        try:
            token = wait_for("merchant token", browser_wait_timeout(30), 0.5, lambda: cdp.eval("localStorage.getItem('token') || ''"))
        except Exception as exc:
            raise RuntimeError(str(exc) + " | state=" + json.dumps(diagnostic_state(cdp), ensure_ascii=False))
        url = cdp.eval("location.href")
        if url.rstrip("/").endswith("/login"):
            raise RuntimeError("Login stored token but did not leave /login")

        profile_result = cdp.eval("""
fetch('/api/user/profile', {headers: {Authorization: localStorage.getItem('token')}})
  .then(async response => ({status: response.status, body: await response.json()}))
""", await_promise=True)
        if int(profile_result.get("status") or 0) != 200 or profile_result.get("body", {}).get("code") != 200:
            raise RuntimeError("Profile check failed: " + json.dumps(profile_result, ensure_ascii=False))

        account_result = cdp.eval("""
fetch('/api/channel/account/list?page=1&limit=200', {headers: {Authorization: localStorage.getItem('token')}})
  .then(async response => ({status: response.status, body: await response.json()}))
""", await_promise=True)
        if int(account_result.get("status") or 0) != 200 or account_result.get("body", {}).get("code") != 200:
            raise RuntimeError("Merchant account list failed: " + json.dumps(account_result, ensure_ascii=False))

        fatal_console = [item for item in cdp.console_errors if "favicon" not in item.lower()]
        if fatal_console:
            raise RuntimeError("Console errors: " + " | ".join(fatal_console[:5]))

        print(json.dumps({
            "ok": True,
            "url": url,
            "token_length": len(token),
            "profile_user": profile_result.get("body", {}).get("data", {}).get("username"),
            "account_count": account_result.get("body", {}).get("data", {}).get("count"),
            "network_errors": cdp.network_errors[:10],
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
        print(f"BROWSER LOGIN TEST FAILED: {exc}", file=sys.stderr)
        sys.exit(1)
