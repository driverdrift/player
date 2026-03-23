from flask import Flask, request, jsonify
import requests
from bs4 import BeautifulSoup
import subprocess
import time
import threading

app = Flask(__name__)

BASE = "https://vidhub4.cc"
lock = threading.Lock()

def load_cookies():
    cookies = {}
    with open("cookies.txt") as f:
        for line in f:
            if line.startswith("#") or not line.strip():
                continue
            parts = line.strip().split("\t")
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
    with lock:
        print("🔄 执行 captcha_solver.sh 更新 cookies...")
        subprocess.run(["/bin/bash", "./captcha_solver.sh"])

def is_expired(html):
    return "请输入验证码" in html

def get_html_with_cookies(url):
    for attempt in range(3):
        cookies = load_cookies()
        r = requests.get(url, cookies=cookies)
        html = r.text

        if is_expired(html):
            print("❌ Cookies失效")
            run_solver()
            time.sleep(1)
            continue

        return html

    raise Exception("多次尝试失败")

def cookie_refresher():
    while True:
        try:
            test_url = f"{BASE}/vodsearch/test----------1---.html"
            cookies = load_cookies()
            r = requests.get(test_url, cookies=cookies)

            if is_expired(r.text):
                print("⚠️ Cookies过期（定时）")
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

    # ✅ 直接拿原分页 HTML
    page_div = soup.select_one("#page")
    pagination_html = str(page_div) if page_div else ""

    return jsonify({
        "results": data,
        "pagination": pagination_html
    })

if __name__ == "__main__":
    t = threading.Thread(target=cookie_refresher, daemon=True)
    t.start()

    app.run(port=5000)
