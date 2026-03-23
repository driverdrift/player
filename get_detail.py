import requests
from bs4 import BeautifulSoup
from jinja2 import Template

url = "https://vidhub4.cc/voddetail/60414.html"
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
body {
    font-family: Arial; 
    padding: 20px; 
    display:flex; 
    justify-content:center; 
    background-color: #f5f5f5;
}
.main-container {
    width: 95%; 
    max-width: 1600px;
}

/* H1靠左 */
h1 {margin: 10px 0; text-align: left;}

/* 顶部信息+封面容器（浅色圆角） */
.info-cover {
    display: flex; 
    justify-content: space-between; 
    margin-bottom: 20px;
    background-color: #ffffff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.info {flex: 2; text-align: left; display: flex; flex-direction: column; justify-content: center;}
.info p {margin: 5px 0;}
.info p strong {display: inline-block; width: 60px; text-align: right; margin-right: 5px;}
.cover {flex: 1; display: flex; justify-content: flex-end; align-items: center;}
.cover img {width: 100%; max-width: 320px; border-radius: 12px; cursor: pointer;}

/* 播放线路+集数+主选/备选容器（深色圆角） */
.play-container {
    margin-bottom: 30px; 
    border-radius: 12px;
    padding: 20px;
    background-color: #e0e0e0;
}

/* 播放线路按钮 */
.line-buttons {display: flex; gap: 10px; flex-wrap: wrap; justify-content:flex-end; margin-bottom: 10px;}
.line-buttons button {padding: 5px 10px; cursor: pointer; border: 1px solid #0077cc; background: white; border-radius: 4px;}
.line-buttons button.active {background: #0077cc; color: white;}

/* 集数 */
.episodes {display: flex; flex-wrap: wrap; gap: 5px; overflow-x: auto; padding-bottom: 10px;}
.episodes li {padding: 5px 10px; border: 1px solid #ccc; border-radius: 4px; white-space: nowrap;}

/* 系列影片容器 */
.series-container {
    display: flex; 
    flex-wrap: wrap; 
    gap: 10px; 
    overflow-x: auto; 
    margin-top: 10px; 
    width: 100%;
}
.series-item {flex: 0 0 calc(16.66% - 10px); display: flex; flex-direction: column; justify-content: flex-end; text-align: center;}
.series-item img {width: 100%; border-radius: 12px; object-fit: cover; display: block;}
.series-item a {display: block; margin-top: 5px; font-size: 14px;}
</style>
</head>
<body>
<div class="main-container">

<h1>{{ title }}</h1>

<!-- 顶部信息+封面 -->
<div class="info-cover">
    <div class="info">
        <p><strong>别名:</strong> {{ subtitle }}</p>
        <p><strong>导演:</strong> {{ directors|join(", ") }}</p>
        <p><strong>主演:</strong> {{ actors|join(", ") }}</p>
        <p><strong>上映:</strong> {{ release }}</p>
        <p><strong>连载:</strong> {{ episodes_current_text }}</p>
        <p><strong>总集数:</strong> {{ episodes_total_text }}</p>
        <p><strong>剧情:</strong> {{ plot }}</p>
    </div>
    <div class="cover">
        <img src="{{ cover_img }}" alt="封面" id="cover-img">
    </div>
</div>

<!-- 播放线路 + 集数 + 主选/备选 -->
<div class="play-container">
    <div class="line-buttons">
        {% for line_name in episode_lists.keys() %}
        <button data-line="{{ line_name }}" {% if loop.first %}class="active"{% endif %}>{{ line_name }}</button>
        {% endfor %}
    </div>

    <ul class="episodes" id="episode-list">
        {% for ep in episode_lists.values()|first %}
        <li><a href="{{ ep.url }}">{{ ep.title }}</a></li>
        {% endfor %}
    </ul>
</div>

<h2>系列影片</h2>
<div class="series-container">
    {% for s in series %}
    <div class="series-item">
        <img src="{{ s.img }}" alt="{{ s.title }}" onclick="window.location.href='{{ s.link }}'">
        <a href="{{ s.link }}">{{ s.title }} ({{ s.year }})</a>
    </div>
    {% endfor %}
</div>

<script>
const episodeLists = {{ episode_lists | tojson }};
const episodeContainer = document.getElementById('episode-list');
const coverImg = document.getElementById('cover-img');

function renderEpisodes(line) {
    const eps = episodeLists[line];
    episodeContainer.innerHTML = '';
    eps.forEach(ep => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = ep.url;
        a.textContent = ep.title;
        li.appendChild(a);
        episodeContainer.appendChild(li);
    });
    if (eps.length > 0) coverImg.onclick = () => { window.location.href = eps[0].url; };
}

document.querySelectorAll('.line-buttons button').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.line-buttons button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        renderEpisodes(btn.dataset.line);
    });
});

// 初始化封面点击
const firstLine = Object.keys(episodeLists)[0];
if (episodeLists[firstLine].length > 0) {
    coverImg.onclick = () => { window.location.href = episodeLists[firstLine][0].url; };
}
</script>

</div>
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
