from flask import Flask, request, jsonify
import requests
from bs4 import BeautifulSoup
import subprocess
import threading
import os

app = Flask(__name__)

BASE = "https://vidhub4.cc"

# 🔒 并发锁，防止多个请求同时刷新 cookies
lock = threading.Lock()


def load_cookies():
    """读取 cookies.txt"""
    cookies = {}
    if not os.path.exists("cookies.txt"):
        return cookies
    with open("cookies.txt") as f:
        for line in f:
            if line.startswith("#") or not line.strip():
                continue
            parts = line.strip().split("\t")
            cookies[parts[5]] = parts[6]
    return cookies


def parse(html):
    """解析搜索页面，返回 JSON 数据"""
    soup = BeautifulSoup(html, "lxml")
    items = []

    for item in soup.select(".module-search-item"):
        title_tag = item.select_one(".video-info-header h3 a")
        detail_tag = item.select_one(".video-info-header a.video-serial")
        play_tag = item.select_one(".video-info-footer a.btn-important")
        cover_tag = item.select_one(".module-item-pic img")

        if not title_tag:
            continue

        items.append({
            "title": title_tag.get("title"),
            "detail": BASE + detail_tag.get("href") if detail_tag else None,
            "play": BASE + play_tag.get("href") if play_tag else None,
            "cover": cover_tag.get("data-src") if cover_tag else None
        })

    return items


def is_captcha_page(html):
    """判断是否被验证码拦截"""
    return "请输入验证码" in html or "人机验证" in html


def refresh_cookies():
    """调用 shell 脚本刷新 cookies"""
    with lock:
        print("⚠️ cookies 失效，执行 captcha_solver.sh ...")
        try:
            subprocess.run(
                ["bash", "captcha_solver.sh"],
                cwd=".",      # 确保在 server.py 所在目录
                timeout=120   # 超时 2 分钟
            )
            print("✅ cookies 已刷新")
        except subprocess.TimeoutExpired:
            print("❌ captcha_solver.sh 执行超时")


@app.route("/search")
def search():
    """搜索接口"""
    wd = request.args.get("wd")
    if not wd:
        return jsonify({"error": "请提供 wd 参数"}), 400

    url = f"{BASE}/vodsearch/{wd}----------1---.html"
    cookies = load_cookies()

    # 第一次请求
    r = requests.get(url, cookies=cookies)
    html = r.text

    # 如果被验证码拦截，刷新 cookies 再请求一次
    if is_captcha_page(html):
        refresh_cookies()
        cookies = load_cookies()
        r = requests.get(url, cookies=cookies)
        html = r.text

        if is_captcha_page(html):
            return jsonify({"error": "验证码识别失败，请稍后再试"}), 503

    data = parse(html)
    return jsonify(data)


if __name__ == "__main__":
    # 外部可访问（虚拟机 VPS）
    app.run(port=5000)
