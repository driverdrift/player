from playwright.sync_api import sync_playwright

def is_master_m3u8(text: str) -> bool:
    return "#EXT-X-STREAM-INF" in text

def is_media_m3u8(text: str) -> bool:
    return "#EXTINF" in text


def extract_m3u8(page_url):
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

            if url in seen:
                return
            seen.add(url)

            try:
                # fake m3u8
                if "m3u8.php" in url:
                    result["fake"] = url
                    result["done"] = True
                    return

                # real m3u8
                if ".m3u8" in url:
                    text = response.text()

                    if is_master_m3u8(text):
                        result["master"] = url
                        result["done"] = True
                        return

                    if is_media_m3u8(text):
                        if not result["media"]:
                            result["media"] = url

            except:
                pass

        page.on("response", handle_response)

        try:
            page.goto(page_url, timeout=15000)
        except:
            pass

        for _ in range(30):
            if result["done"]:
                break
            page.wait_for_timeout(100)

        browser.close()

        return result["master"] or result["fake"] or result["media"]
