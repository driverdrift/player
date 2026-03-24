from flask import Flask, request, jsonify
import requests
from bs4 import BeautifulSoup
import subprocess
import time
import threading
import os
import re

from detail_parser import parse_detail   # 👈 引入

app = Flask(__name__)

BASE = "https://vidhub4.cc"
lock = threading.Lock()


def load_cookies():
    cookies = {}

    if not os.path.exists("cookies.txt"):
        open("cookies.txt", "w").close()
        return cookies

    with open("cookies.txt") as f:
        for line in f:
            if line.startswith("#") or not line.strip():
                continue
            parts = line.strip().split("\t")
            if len(parts) >= 7:
                cookies[parts[5]] = parts[6]

    return cookies


def extract_id(url):
    if not url:
        return None
    m = re.search(r'/voddetail/(\d+)', url)
    return m.group(1) if m else None


def extract_play_id(url):
    if not url:
        return None
    m = re.search(r'/vodplay/([^\.]+)', url)
    return m.group(1) if m else None


def parse(html):
    soup = BeautifulSoup(html, "lxml")
    items = []

    for item in soup.select(".module-search-item"):
        title_tag = item.select_one(".video-info-header h3 a")
        detail_tag = item.select_one(".video-info-header a.video-serial")
        play_tag = item.select_one(".video-info-footer a.btn-important")
        cover_tag = item.select_one(".module-item-pic img")

        if not title_tag:
            continue

        detail_url = BASE + detail_tag.get("href") if detail_tag else None
        play_url = BASE + play_tag.get("href") if play_tag else None

        detail_id = extract_id(detail_url)
        play_id = extract_play_id(play_url)

        items.append({
            "title": title_tag.get("title"),
            "detail": f"/detail/{detail_id}" if detail_id else None,
            "play": f"/play/{play_id}" if play_id else None,
            "cover": cover_tag.get("data-src") if cover_tag else None
        })

    return items


def run_solver():
    with lock:
        print("🔄 执行 captcha_solver.sh 更新 cookies...")
        subprocess.run(["/bin/bash", "./captcha_solver.sh"])

        if not os.path.exists("cookies.txt"):
            raise Exception("❌ captcha_solver.sh 未生成 cookies.txt")


def is_expired(html):
    return "请输入验证码" in html


def get_html_with_cookies(url):
    for attempt in range(3):
        cookies = load_cookies()
        r = requests.get(url, cookies=cookies)
        html = r.text

        if is_expired(html):
            print("❌ Cookies失效，刷新中...")
            run_solver()
            time.sleep(1)
            continue

        return html

    raise Exception("❌ 多次尝试获取网页失败")


def cookie_refresher():
    while True:
        try:
            test_url = f"{BASE}/vodsearch/test----------1---.html"
            cookies = load_cookies()
            r = requests.get(test_url, cookies=cookies)

            if is_expired(r.text):
                print("⚠️ Cookies过期（定时检测）")
                run_solver()
        except Exception as e:
            print("检测异常:", e)

        time.sleep(300)


@app.route("/search")
def search():
    wd = request.args.get("wd")
    page = request.args.get("page", "1")

    url = f"{BASE}/vodsearch/{wd}----------{page}---.html"
    html = get_html_with_cookies(url)

    soup = BeautifulSoup(html, "lxml")
    data = parse(html)

    page_div = soup.select_one("#page")
    pagination_html = str(page_div) if page_div else ""

    return jsonify({
        "results": data,
        "pagination": pagination_html
    })


# ✅ 详情页（核心）
@app.route("/detail/<id>")
def detail(id):
    url = f"{BASE}/voddetail/{id}.html"
    html = get_html_with_cookies(url)

    clean_html = parse_detail(html, BASE)

    return clean_html


# ✅ 播放页（先占位）
@app.route("/play/<path:pid>")
def play(pid):
    return f"播放页开发中: {pid}"


if __name__ == "__main__":
    try:
        run_solver()
    except Exception as e:
        print("❌ 启动时生成 cookies 失败:", e)

    t = threading.Thread(target=cookie_refresher, daemon=True)
    t.start()

    app.run(port=5000)
