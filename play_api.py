# Usage: curl "http://localhost:5001/parse?url=https://xxx.com/play/abc123"
from flask import Flask, request, jsonify
from m3u8_extractor import extract_m3u8
from urllib.parse import urlparse

app = Flask(__name__)


# =========================
# 校验 URL
# =========================
def is_valid_url(url):
    try:
        result = urlparse(url)
        return result.scheme in ("http", "https") and result.netloc
    except:
        return False


# =========================
# 核心解析 API
# =========================
@app.route("/parse")
def parse():
    target_url = request.args.get("url")

    if not target_url or not is_valid_url(target_url):
        return jsonify({"error": "invalid url"})

    print("解析:", target_url)

    m3u8_url = extract_m3u8(target_url)

    if not m3u8_url:
        return jsonify({"error": "no stream found"})

    # 类型识别
    if "m3u8.php" in m3u8_url:
        return jsonify({
            "type": "fake",
            "url": m3u8_url
        })

    if ".m3u8" in m3u8_url:
        return jsonify({
            "type": "m3u8",
            "url": m3u8_url
        })

    return jsonify({
        "type": "unknown",
        "url": m3u8_url
    })


# =========================
# 健康检查
# =========================
@app.route("/")
def index():
    return "universal play api running"


if __name__ == "__main__":
    app.run(port=5001)
