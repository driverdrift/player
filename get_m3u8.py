from playwright.sync_api import sync_playwright

def is_master_m3u8(text: str) -> bool:
    return "#EXT-X-STREAM-INF" in text

def is_media_m3u8(text: str) -> bool:
    return "#EXTINF" in text


def run(page_url):
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()

        result = {
            "done": False,
            "master": None,
            "media": None,
            "fake": None
        }

        seen = set()

        def handle_response(response):
            if result["done"]:
                return

            url = response.url

            # 防重复
            if url in seen:
                return
            seen.add(url)

            try:
                # =========================
                # 1️⃣ 伪 m3u8（优先级最高）
                # =========================
                if "m3u8.php" in url:
                    result["fake"] = url
                    result["done"] = True

                    print("伪 m3u8:", url)
                    return

                # =========================
                # 2️⃣ 真实 m3u8
                # =========================
                if ".m3u8" in url:
                    text = response.text()

                    # master（最高优先级）
                    if is_master_m3u8(text):
                        result["master"] = url
                        result["done"] = True

                        print("master m3u8:", url)
                        return

                    # media（兜底）
                    if is_media_m3u8(text):
                        if not result["media"]:
                            result["media"] = url
                            print("media m3u8:", url)

            except Exception:
                pass

        page.on("response", handle_response)

        try:
            page.goto(page_url, timeout=15000)
        except:
            pass

        # =========================
        # ✅ 安全提前结束机制
        # =========================
        for _ in range(30):  # 最多等3秒（30 * 0.1）
            if result["done"]:
                break
            page.wait_for_timeout(100)

        browser.close()

        # 返回优先级
        return result["master"] or result["fake"] or result["media"]


if __name__ == "__main__":
    url = "https://vidhub4.cc/vodplay/195957-2-1.html"
    final = run(url)

    print("\n最终结果:", final)
