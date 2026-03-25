<?php
ob_start();
session_start();

// --- 配置区 ---
$db_file = 'list.json';
$admin_password = "admin123";
$php_self = explode('?', $_SERVER['REQUEST_URI'])[0];

// 初始化数据库文件
if (!file_exists($db_file)) {
    file_put_contents($db_file, json_encode([], JSON_UNESCAPED_UNICODE));
}

/**
 * 增强版安全保存函数
 */
function safe_save($file, $data) {
    array_walk_recursive($data, function(&$item) {
        if (is_string($item)) {
            $item = mb_convert_encoding($item, 'UTF-8', 'UTF-8,GBK,GB2312,BIG5');
            $item = trim($item);
        }
    });
    $json_str = json_encode(array_values($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json_str === false) {
        die("数据保存失败！错误原因: " . json_last_error_msg());
    }
    return file_put_contents($file, $json_str);
}

// --- 逻辑处理区 ---

// 1. 登录/退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    unset($_SESSION['admin']);
    header("Location: " . $php_self); exit;
}
if (isset($_POST['login_pass']) && $_POST['login_pass'] === $admin_password) {
    $_SESSION['admin'] = true;
    header("Location: " . $php_self); exit;
}
$is_admin = isset($_SESSION['admin']);

// 2. 增删改处理
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_video'])) {
    $data = json_decode(file_get_contents($db_file), true) ?: [];
    $id = $_POST['id'] ?: uniqid();

    // 使用 /u 支持中文字符分割
    $raw_groups = preg_split('/[,，\s|;；]+/u', $_POST['group']);
    $groups = array_values(array_unique(array_filter(array_map('trim', $raw_groups)))) ?: ['默认'];

    $new_entry = [
        'id' => $id,
        'title' => trim($_POST['title']),
        'url' => trim($_POST['url']),
        'groups' => $groups,
        'time' => $_POST['time'] ?: time()
    ];

    $found = false;
    if (!empty($_POST['id'])) {
        foreach ($data as &$item) {
            if ($item['id'] == $id) { $item = $new_entry; $found = true; break; }
        }
    }
    if (!$found) { $data[] = $new_entry; }

    safe_save($db_file, $data);
    header("Location: " . $php_self); exit;
}

// 3. 删除逻辑 (修复版)
if ($is_admin && isset($_GET['delete_id'])) {
    $target_id = $_GET['delete_id'];
    // 注意：这里的空格要和 groups_map 里的 key 完全一致
    $from_group = isset($_GET['from_group']) ? trim(urldecode($_GET['from_group'])) : '全部内容';
    $data = json_decode(file_get_contents($db_file), true) ?: [];

    $new_data = [];
    foreach ($data as $item) {
        if ($item['id'] == $target_id) {
            // 如果是在 "全部内容" 下点击删除，直接跳过该条目 (即彻底删除)
            if (trim($from_group) === '全部内容') {
                continue;
            }

            // 如果是在特定分组下点击删除，仅移除该分组标签
            $item['groups'] = array_values(array_diff($item['groups'] ?: [], [$from_group]));

            // 如果移除后一个分组都没了，说明该视频不属于任何地方，将其彻底删除
            // 或者如果你希望保留，就取消下面这一行的注释，它会流转到默认分组
            if (empty($item['groups'])) continue;

            $new_data[] = $item;
        } else {
            $new_data[] = $item;
        }
    }
    safe_save($db_file, $new_data);
    header("Location: " . $php_self); exit;
}

// 4. 数据预处理
$all_data = json_decode(file_get_contents($db_file), true) ?: [];
usort($all_data, function($a, $b) { return ($b['time'] ?? 0) - ($a['time'] ?? 0); });
$groups_map = [" 全部内容 " => $all_data];
foreach ($all_data as $item) {
    if (!empty($item['groups'])) {
        foreach ($item['groups'] as $g) {
            $g = trim($g);
            if($g !== "") $groups_map[$g][] = $item;
        }
    }
}
ksort($groups_map);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PRIVATE VOD - YouTube Style</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet" />
    <style>
        /* 这里完全恢复了你第一个版本的原始 CSS */
        :root { --accent: #ff0000; --bg: #0f0f0f; --panel: #1a1a1a; }
        body { background: var(--bg); color: #fff; height: 100vh; display: flex; overflow: hidden; margin: 0; }

        .sidebar { width: 350px; background: var(--panel); border-right: 1px solid #333; display: flex; flex-direction: column; z-index: 10; }
        .main-content { flex: 1; padding: 20px; background: #000; overflow-y: auto; }

        .group-header { background: #252525; color: #aaa; padding: 8px 15px; font-weight: bold; font-size: 12px; margin-top: 5px; }
        .video-item { padding: 10px 15px; border-bottom: 1px solid #222; cursor: pointer; transition: 0.2s; }
        .video-item:hover { background: #333; }
        .video-item.active { background: #3d3d3d; border-left: 4px solid var(--accent); }

        /* Video.js 布局修正补丁 */
        .video-container { border-radius: 0; overflow: hidden; background: #000; position: relative; }

        .video-js .vjs-control-bar {
            display: flex !important;
            background: linear-gradient(transparent, rgba(0,0,0,0.8)) !important;
            height: 50px !important;
            padding: 0 10px !important;
            align-items: center;
        }

        .video-js .vjs-spacer { display: flex !important; flex: 1 1 auto !important; }

/* --- 修改后的进度条样式 --- */

.video-js .vjs-progress-control {
    position: absolute !important;
    width: 100% !important;
    height: 20px !important; /* 固定一个稍大的点击区域，防止跳动 */
    top: -10px; /* 调整位置，使其悬浮在控制栏上方 */
    left: 0;
    padding: 0 !important;
    margin: 0 !important;
    display: flex;
    align-items: center; /* 确保进度条在感应区内垂直居中 */
}

/* 移除之前的 :hover height: 30px，改为对内部进度条进行视觉增强 */
.video-js .vjs-progress-control:hover .vjs-progress-holder {
    height: 6px !important; /* 鼠标移上去时，进度条稍微变粗一点，而不是整个控件变高 */
    transition: height 0.1s;
}

.video-js .vjs-progress-holder {
    height: 3px !important; /* 默认时细条 */
    margin: 0 !important;
    transition: height 0.1s;
}
        .video-js .vjs-play-progress { background-color: var(--accent) !important; }
        .video-js .vjs-play-progress:before { display: block !important; }

        /* 按钮与文字垂直居中对齐补丁 */
        .vjs-button > .vjs-icon-placeholder:before { line-height: 50px !important; }
        .video-js .vjs-time-control { line-height: 50px !important; min-width: auto; padding: 0 5px; display: flex; align-items: center; }

        /* 倍速按钮对齐 */
        .video-js .vjs-playback-rate { line-height: 50px !important; display: flex !important; align-items: center !important; }
        .video-js .vjs-playback-rate .vjs-playback-rate-value { line-height: 50px !important; display: block; }

        /* 音量条高度与位置对齐修正 */
        .video-js .vjs-volume-panel { display: flex !important; align-items: center !important; }
        .video-js .vjs-volume-level { background-color: var(--accent) !important; }

        .inline-edit-panel { background: #222; border: 1px solid #444; padding: 15px; margin: 5px; border-radius: 8px; }
        .form-control { background: #333 !important; color: #fff !important; border: 1px solid #444 !important; }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: #444; }

        /* 占位符颜色补丁 */
        .form-control::placeholder { color: #aaa !important; opacity: 1; }

.video-js .vjs-qualitymenubutton::before {
    content: "⚙️";
    color: #fff;
    font-size: 16px;
    line-height: normal;
}
.video-js .vjs-floating-dot {
    position: absolute;
    width: 10px;       /* 小点大小 */
    height: 10px;
background: rgba(255,0,0,0.01); /* 极低透明度，但浏览器仍认为可见 */
    top: -400px;       /* 漂浮高度，可根据需求调整 */
    left: 50%;         /* 水平居中，也可以改为你想要的位置 */
    pointer-events: none; /* 不阻塞鼠标点击 */
    z-index: 1000;     /* 保证在控制栏上方 */
}
        .video-container {
    width: 100%;
    aspect-ratio: 16 / 9;   /* ✅ 关键 */
    background: #000;
}
    </style>
</head>
<body>

<div class="sidebar">
    <div class="p-3 d-flex justify-content-between align-items-center border-bottom border-secondary">
        <h6 class="m-0 fw-bold"><i class="bi bi-youtube text-danger me-2"></i>PRIVATE VOD</h6>
        <?php if($is_admin): ?>
            <button onclick="navTo('action=logout')" class="btn btn-dark btn-sm py-0 border-secondary" style="font-size:11px;">退出管理</button>
        <?php else: ?>
            <button onclick="showLogin()" class="btn btn-dark btn-sm py-0 border-secondary" style="font-size:11px;">登录</button>
        <?php endif; ?>
    </div>

    <div class="p-2">
        <input type="text" id="search" class="form-control form-control-sm" placeholder="搜索影片..." onkeyup="doSearch()">
    </div>

    <div class="flex-grow-1 overflow-auto">
        <?php if($is_admin): ?>
            <button class="btn btn-outline-info btn-sm w-100 rounded-0 border-0 py-2 mb-2" onclick="openInlineAdd()">+ 添加视频</button>
            <div id="inline-add-area"></div>
        <?php endif; ?>

        <?php foreach ($groups_map as $gname => $items): ?>
            <div class="group-header text-uppercase"><?php echo htmlspecialchars($gname); ?></div>
            <?php foreach ($items as $v): ?>
                <div class="video-container-unit" data-unit-id="<?php echo $v['id']; ?>">
                    <div class="video-item d-flex justify-content-between align-items-center"
                         data-info='<?php echo htmlspecialchars(json_encode($v), ENT_QUOTES, 'UTF-8'); ?>'
                         onclick="playVideo('<?php echo $v['url']; ?>', '<?php echo addslashes($v['title']); ?>', this)">
                        <div class="text-truncate" style="max-width: 220px;"><i class="bi bi-play-circle me-2"></i><?php echo htmlspecialchars($v['title']); ?></div>
                        <?php if($is_admin): ?>
                            <div class="small">
                                <i class="bi bi-pencil-square text-muted me-2" onclick="event.stopPropagation(); openInlineEdit('<?php echo $v['id']; ?>', this)"></i>
                                <i class="bi bi-x-lg text-muted" style="cursor:pointer;" onclick="event.stopPropagation(); confirmDel('<?php echo $v['id']; ?>', '<?php echo urlencode($gname); ?>')"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div id="edit-slot-<?php echo $v['id']; ?>"></div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="main-content">
    <h4 id="v-title" class="mb-3 fw-bold">等待播放...</h4>
    <div class="video-container">
        <video id="player" class="video-js vjs-big-play-centered w-100"
                controls preload="auto" height="600"></video>
    </div>
    <div class="mt-3 p-3 bg-dark rounded">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="badge bg-secondary">播放源</span>
            <code id="video-url-display" class="text-info small"></code>
        </div>
        <p class="text-secondary small mb-0">快捷键：空格 (播放/暂停)、M (静音)、F (全屏)。</p>
    </div>
</div>

<div id="edit-template" class="d-none">
    <div class="inline-edit-panel">
        <form method="POST">
            <input type="hidden" name="id" class="t-id">
            <input type="hidden" name="time" class="t-time">
            <input type="text" name="title" class="form-control t-title mb-2" placeholder="视频标题" required>
            <input type="text" name="url" class="form-control t-url mb-2" placeholder="M3U8 链接" required>
            <input type="text" name="group" class="form-control t-group mb-2" placeholder="分组 (逗号分隔)">
            <div class="d-flex gap-2">
                <button type="submit" name="save_video" class="btn btn-danger btn-sm w-100">保存</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeEdit(this)">取消</button>
            </div>
        </form>
    </div>
</div>

<div id="loginPop" class="modal" style="background: rgba(0,0,0,0.85);">
    <div class="modal-dialog modal-sm mt-5">
        <div class="modal-content bg-dark border-secondary p-3">
            <form method="POST">
                <input type="password" name="login_pass" class="form-control mb-3" placeholder="管理密码">
                <button type="submit" class="btn btn-danger w-100">确认登录</button>
            </form>
        </div>
    </div>
</div>

<script src="./video.min.js"></script>
<script src="./hls.js@latest"></script>

<script>
const vPlayer = videojs('player', {
    fluid: true,
    aspectRatio: '16:9',   // ✅ 新增这一行
    playbackRates: [0.5, 1, 1.25, 1.5, 2],
    controlBar: {
        children: [
            'progressControl',
            'playToggle',
            'volumePanel',
            'currentTimeDisplay',
            'timeDivider',
            'durationDisplay',
            'spacer',
            'playbackRateMenuButton',
            'QualityMenuButton',
            'fullscreenToggle'
        ]
    }
});

let hls = null;
let globalOffset = null;

// 1️⃣ 确保 video 元素可聚焦
vPlayer.ready(function() {
    const videoEl = vPlayer.el();
    videoEl.tabIndex = -1;
    videoEl.focus();
    vPlayer.userActive(true);
});

// 2️⃣ 获取 video DOM
function getVideoEl() {
    return vPlayer.el().getElementsByTagName('video')[0];
}

// 3️⃣ 页面导航
function navTo(query) { window.location.href = window.location.pathname + '?' + query; }
function confirmDel(id, groupEncoded) {
    if(confirm('确认从此分类中删除吗？')) { navTo(`delete_id=${id}&from_group=${groupEncoded}`); }
}

// 4️⃣ HLS Fake 处理
function findTsOffset(uint8arr) {
    for (let i = 0; i < uint8arr.length - 1; i++) {
        if (uint8arr[i] === 0x47 && uint8arr[i + 1] === 0x40) return i;
    }
    return 0;
}

async function processFakeM3U8(url) {
    const res = await fetch(url);
    const text = await res.text();
    const lines = text.split('\n');
    let firstTs = null;

    for (let line of lines) {
        line = line.trim();
        if (line && !line.startsWith('#')) {
            firstTs = line;
            break;
        }
    }
    if (!firstTs) return url;

    const tsUrl = new URL(firstTs, url).href;
    if (globalOffset === null) {
        const tsRes = await fetch(tsUrl);
        const buf = await tsRes.arrayBuffer();
        globalOffset = findTsOffset(new Uint8Array(buf));
        console.log("TS Offset:", globalOffset);
    }

    return URL.createObjectURL(new Blob([text], { type: 'application/vnd.apple.mpegurl' }));
}

// 5️⃣ 播放视频
function playVideo(url, title, el) {
    document.getElementById('v-title').innerText = title;
    document.getElementById('video-url-display').innerText = url;

    document.querySelectorAll('.video-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');

    if(hls){ hls.destroy(); hls = null; }
    vPlayer.pause();

    const video = getVideoEl();
    video.focus();           // 聚焦播放器
    vPlayer.userActive(true); // 强制显示控制栏

    if(url.endsWith('.m3u8.fake')) handleFake(url, video);
    else handleNormal(url, video);
}

// 6️⃣ 重建清晰度按钮
function rebuildQualityButton() {
    const old = vPlayer.controlBar.getChild('QualityMenuButton');
    if (old) vPlayer.controlBar.removeChild(old);
    vPlayer.controlBar.addChild('QualityMenuButton', {}, vPlayer.controlBar.children().length - 1);
}

// 7️⃣ 正常播放
function handleNormal(url, video) {
    if(Hls.isSupported()) {
        hls = new Hls();
        hls.loadSource(url);
        hls.attachMedia(video);
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            rebuildQualityButton();
            vPlayer.play();
        });
    } else {
        vPlayer.src({ src: url, type: 'application/x-mpegURL' });
        vPlayer.play();
    }
}

// 8️⃣ Fake HLS 播放
async function handleFake(url, video) {
    const processedUrl = await processFakeM3U8(url);
    hls = new Hls({
        loader: class extends Hls.DefaultConfig.loader {
            load(context, config, callbacks) {
                const originalOnSuccess = callbacks.onSuccess;
                callbacks.onSuccess = function(response, stats, context) {
                    if(context.type === 'fragment' && globalOffset > 0){
                        let data = new Uint8Array(response.data);
                        if(data.length > globalOffset) data = data.slice(globalOffset);
                        response.data = data.buffer;
                    }
                    originalOnSuccess(response, stats, context);
                };
                super.load(context, config, callbacks);
            }
        }
    });
    hls.loadSource(processedUrl);
    hls.attachMedia(video);
    hls.on(Hls.Events.MANIFEST_PARSED, () => {
        rebuildQualityButton();
        vPlayer.play();
    });
}

// 9️⃣ 清晰度菜单
const MenuButton = videojs.getComponent('MenuButton');
const MenuItem = videojs.getComponent('MenuItem');
class QualityMenuButton extends MenuButton {
    constructor(player, options) { super(player, options); this.controlText('清晰度'); }
    buildCSSClass() { return 'vjs-menu-button vjs-menu-button-icon vjs-qualitymenubutton'; }
    createItems() {
        if(!hls || !hls.levels || hls.levels.length <= 1) return [];
        const items = [];
        items.push(new MenuItem(this.player_, { label:'AUTO', selectable:true, selected:hls.currentLevel===-1 }, () => { hls.currentLevel=-1; }));
        hls.levels.forEach((level,i)=>{
            const h = level.height||'', b = Math.round(level.bitrate/1000);
            const label = h?`${h}p`:`${b}k`;
            items.push(new MenuItem(this.player_, { label, selectable:true, selected:hls.currentLevel===i }, ()=>{hls.currentLevel=i;}));
        });
        return items;
    }
}
videojs.registerComponent('QualityMenuButton', QualityMenuButton);

// 10️⃣ 搜索
function doSearch() {
    const kw = document.getElementById('search').value.toLowerCase();
    document.querySelectorAll('.video-container-unit').forEach(unit=>{
        const title = unit.querySelector('.video-item').innerText.toLowerCase();
        unit.style.display = title.includes(kw)?'block':'none';
    });
}

// 11️⃣ 内联编辑/添加
function openInlineEdit(id, btn) {
    document.querySelectorAll('.video-container-unit .inline-edit-panel').forEach(p=>p.remove());
    const itemEl = btn.closest('.video-item');
    const data = JSON.parse(itemEl.getAttribute('data-info'));
    const slot = document.getElementById(`edit-slot-${id}`);
    const template = document.getElementById('edit-template').querySelector('.inline-edit-panel').cloneNode(true);
    template.querySelector('.t-id').value = data.id;
    template.querySelector('.t-time').value = data.time||'';
    template.querySelector('.t-title').value = data.title;
    template.querySelector('.t-url').value = data.url;
    template.querySelector('.t-group').value = data.groups.join(', ');
    slot.appendChild(template);
}
function openInlineAdd() {
    const area = document.getElementById('inline-add-area');
    area.innerHTML = '';
    const template = document.getElementById('edit-template').querySelector('.inline-edit-panel').cloneNode(true);
    area.appendChild(template);
}
function closeEdit(btn) { btn.closest('.inline-edit-panel').remove(); }
function showLogin() { document.getElementById('loginPop').style.display='block'; }
window.onclick = e => { if(e.target==document.getElementById('loginPop')) document.getElementById('loginPop').style.display='none'; }

// 12️⃣ 鼠标移动保持控制栏显示 + 聚焦
vPlayer.on('mousemove', ()=>{
    vPlayer.userActive(true);
    getVideoEl().focus();
});

// 13️⃣ 全局键盘事件（空格播放/暂停, M 静音, F 全屏）
document.addEventListener('keydown', function(e){
    const tag = e.target.tagName.toLowerCase();
    if(tag==='input' || tag==='textarea') return;

    switch(e.code){
        case 'Space':
            e.preventDefault();
            if(vPlayer.paused()) vPlayer.play();
            else vPlayer.pause();
            break;
        case 'KeyM':
            e.preventDefault();
            vPlayer.muted(!vPlayer.muted());
            break;
        case 'KeyF':
            e.preventDefault();
            if(!vPlayer.isFullscreen()) vPlayer.requestFullscreen();
            else vPlayer.exitFullscreen();
            break;
    }
});
const controlBar = document.querySelector('.video-js .vjs-control-bar');
const dot = document.createElement('div');
dot.className = 'vjs-floating-dot';
controlBar.appendChild(dot);
</script>
</body>
</html>
