import requests
from bs4 import BeautifulSoup
from jinja2 import Template

url = "https://vidhub4.cc/voddetail/175937.html"
headers = {"User-Agent": "Mozilla/5.0"}

resp = requests.get(url, headers=headers)
resp.encoding = resp.apparent_encoding
soup = BeautifulSoup(resp.text, "html.parser")

# -----------------------
# 基础信息
# -----------------------
title = soup.find("h1", class_="page-title").get_text(strip=True)
subtitle_tag = soup.find("h2", class_="video-subtitle")
subtitle = subtitle_tag.get_text(strip=True) if subtitle_tag else ""

def get_info(label_text):
    label = soup.find(lambda tag: tag.name == "span" and label_text in tag.get_text())
    items = []
    if label:
        sibling_div = label.find_next_sibling("div")
        if sibling_div:
            items = [a.get_text(strip=True) for a in sibling_div.find_all("a")]
    return items

directors = get_info("导演")
actors = get_info("主演")

release_label = soup.find(lambda tag: tag.name == "span" and "上映" in tag.get_text())
release = release_label.find_next_sibling("div").get_text(strip=True) if release_label else "未知"

episodes_total = soup.find(lambda tag: tag.name == "span" and "总集数" in tag.get_text())
episodes_total_text = episodes_total.find_next_sibling("div").get_text(strip=True) if episodes_total else "未知"

episodes_current = soup.find(lambda tag: tag.name == "span" and "连载" in tag.get_text())
episodes_current_text = episodes_current.find_next_sibling("div").get_text(strip=True) if episodes_current else "未知"

plot_label = soup.find(lambda tag: tag.name == "span" and "剧情" in tag.get_text())
plot_tag = plot_label.find_next_sibling("div") if plot_label else None
plot = plot_tag.get_text(strip=True) if plot_tag else "暂无简介"

img_tag = soup.select_one(".video-info-main img") or soup.select_one("meta[property='og:image']")
cover_img = ""
if img_tag:
    if img_tag.name == "meta":
        cover_img = img_tag.get("content", "")
    else:
        cover_img = img_tag.get("data-src") or img_tag.get("src") or ""

# -----------------------
# 播放线路和集数
# -----------------------
lines = [line.get("data-dropdown-value") for line in soup.select(".module-tab-item.tab-item")]

episode_lists = {}
for i, tab in enumerate(soup.select(".module-list.module-player-list"), start=1):
    episodes = []
    for a in tab.select(".sort-item a"):
        ep_title = a.get_text(strip=True)
        ep_url = a.get("href")
        episodes.append({"title": ep_title, "url": ep_url})
    line_name = lines[i-1] if i-1 < len(lines) else f"线路{i}"
    episode_lists[line_name] = episodes

# -----------------------
# 系列影片
# -----------------------
series = []
series_module = soup.find("h2", class_="module-title", title=lambda t: t and "系列影片" in t)
if series_module:
    series_container = series_module.find_parent("div", class_="module")
    if series_container:
        for item in series_container.select(".module-item"):
            title_tag = item.find("a", class_="module-item-title")
            year_tag = item.find("span")
            img_tag = item.find("img")
            if title_tag and img_tag:
                series.append({
                    "title": title_tag.get_text(strip=True),
                    "year": year_tag.get_text(strip=True) if year_tag else "",
                    "img": img_tag.get("data-src") or img_tag.get("src"),
                    "link": title_tag.get("href")
                })

# -----------------------
# HTML 模板（无相关影片）
# -----------------------
html_template = """
<!DOCTYPE html>
<html lang="zh-cn">
<head>
<meta charset="UTF-8">
<title>{{ title }}</title>
<style>
body {font-family: Arial; padding: 20px;}
img {max-width: 200px;}
ul {list-style: none; padding-left: 0;}
li {margin: 5px 0;}
h2 {margin-top: 30px;}
a {text-decoration: none; color: #0077cc;}
a:hover {text-decoration: underline;}
</style>
</head>
<body>
<h1>{{ title }}</h1>
<p><strong>别名:</strong> {{ subtitle }}</p>
<p><strong>导演:</strong> {{ directors|join(", ") }}</p>
<p><strong>主演:</strong> {{ actors|join(", ") }}</p>
<p><strong>上映时间:</strong> {{ release }}</p>
<p><strong>连载:</strong> {{ episodes_current_text }}</p>
<p><strong>总集数:</strong> {{ episodes_total_text }}</p>
<p><strong>剧情:</strong> {{ plot }}</p>
<h2>封面</h2>
<img src="{{ cover_img }}" alt="封面">

<h2>播放线路</h2>
{% for line_name, episodes in episode_lists.items() %}
<h3>{{ line_name }}</h3>
<ul>
{% for ep in episodes %}
<li><a href="{{ ep.url }}">{{ ep.title }}</a></li>
{% endfor %}
</ul>
{% endfor %}

<h2>系列影片</h2>
<ul>
{% for s in series %}
<li>
<a href="{{ s.link }}">{{ s.title }} ({{ s.year }})</a><br>
<img src="{{ s.img }}" width="100">
</li>
{% endfor %}
</ul>

</body>
</html>
"""

template = Template(html_template)
html_content = template.render(
    title=title,
    subtitle=subtitle,
    directors=directors,
    actors=actors,
    release=release,
    episodes_current_text=episodes_current_text,
    episodes_total_text=episodes_total_text,
    plot=plot,
    cover_img=cover_img,
    episode_lists=episode_lists,
    series=series
)

with open("output.html", "w", encoding="utf-8") as f:
    f.write(html_content)

print("HTML 文件已生成：output.html")
