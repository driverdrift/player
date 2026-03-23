from flask import Flask, request, jsonify
import requests
from bs4 import BeautifulSoup
import subprocess
import time
import threading
import os

app = Flask(__name__)

BASE = "https://vidhub4.cc"
lock = threading.Lock()


def load_cookies():
    """
    读取 cookies.txt，如果文件不存在就创建空文件返回空 dict
    """
    cookies = {}

    if not os.path.exists("cookies.txt"):
        # 创建空文件，避免报错
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

        items.append({
            "title": title_tag.get("title"),
            "detail": BASE + detail_tag.get("href") if detail_tag else None,
            "play": BASE + play_tag.get("href") if play_tag else None,
            "cover": cover_tag.get("data-src") if cover_tag else None
        })

    return items


def run_solver():
    """
    调用外部 bash 脚本更新 cookies，锁保护避免并发冲突
    """
    with lock:
        print("🔄 执行 captcha_solver.sh 更新 cookies...")
        subprocess.run(["/bin/bash", "./captcha_solver.sh"])

        # 确保文件存在
        if not os.path.exists("cookies.txt"):
            raise Exception("❌ captcha_solver.sh 未生成 cookies.txt")


def is_expired(html):
    return "请输入验证码" in html


def get_html_with_cookies(url):
    """
    使用 cookies 请求网页，如果失效自动刷新
    """
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
    """
    定时检测 cookies 是否过期
    """
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

        time.sleep(300)  # 每 5 分钟检测一次


@app.route("/search")
def search():
    wd = request.args.get("wd")
    page = request.args.get("page", "1")

    url = f"{BASE}/vodsearch/{wd}----------{page}---.html"
    html = get_html_with_cookies(url)

    soup = BeautifulSoup(html, "lxml")
    data = parse(html)

    # 获取原分页 HTML
    page_div = soup.select_one("#page")
    pagination_html = str(page_div) if page_div else ""

    return jsonify({
        "results": data,
        "pagination": pagination_html
    })


if __name__ == "__main__":
    # 启动时先生成一次 cookies，避免第一次请求失败
    try:
        run_solver()
    except Exception as e:
        print("❌ 启动时生成 cookies 失败:", e)

    # 启动定时刷新线程
    t = threading.Thread(target=cookie_refresher, daemon=True)
    t.start()

    app.run(port=5000)
