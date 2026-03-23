<?php
session_start();
$current_url = $_SERVER['PHP_SELF'];
$db_file = 'list.json';
$admin_password = "admin123"; 

if (!file_exists($db_file)) { file_put_contents($db_file, json_encode([])); }

// 登录/退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') { unset($_SESSION['admin']); echo "<script>location.href='$current_url';</script>"; exit; }
if (isset($_POST['login_pass']) && $_POST['login_pass'] === $admin_password) { $_SESSION['admin'] = true; echo "<script>location.href='$current_url';</script>"; exit; }
$is_admin = isset($_SESSION['admin']);

// 增删改处理
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_video'])) {
    $data = json_decode(file_get_contents($db_file), true) ?: [];
    $id = $_POST['id'] ?: uniqid();
    $raw_groups = preg_split('/[,，\s|;；]+/', $_POST['group']);
    $groups = array_values(array_unique(array_filter(array_map('trim', $raw_groups)))) ?: ['默认'];
    
    $new_entry = [
        'id' => $id,
        'title' => $_POST['title'],
        'url' => $_POST['url'],
        'groups' => $groups,
        'time' => $_POST['time'] ?: time()
    ];
    
    if ($_POST['id']) {
        foreach ($data as &$item) { if ($item['id'] == $id) { $item = $new_entry; break; } }
    } else { $data[] = $new_entry; }
    
    file_put_contents($db_file, json_encode(array_values($data), JSON_UNESCAPED_UNICODE));
    echo "<script>location.href='$current_url';</script>"; exit;
}

// 删除逻辑
if ($is_admin && isset($_GET['delete_id'])) {
    $target_id = $_GET['delete_id'];
    $from_group = $_GET['from_group'] ?? ' 全部内容 ';
    $data = json_decode(file_get_contents($db_file), true) ?: [];
    
    $new_data = [];
    foreach ($data as $item) {
        if ($item['id'] == $target_id) {
            if ($from_group === ' 全部内容 ') {
                continue; 
            } else {
                $item['groups'] = array_values(array_diff($item['groups'], [$from_group]));
                if (empty($item['groups'])) { $item['groups'] = ['默认']; }
                $new_data[] = $item;
            }
        } else {
            $new_data[] = $item;
        }
    }
    file_put_contents($db_file, json_encode(array_values($new_data), JSON_UNESCAPED_UNICODE));
    echo "<script>location.href='$current_url';</script>"; exit;
}

// 数据分组预处理
$all_data = json_decode(file_get_contents($db_file), true) ?: [];
usort($all_data, function($a, $b) { return $b['time'] - $a['time']; });
$groups_map = [" 全部内容 " => $all_data]; 
foreach ($all_data as $item) { foreach ($item['groups'] as $g) { $groups_map[$g][] = $item; } }
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
        :root { --accent: #ff0000; --bg: #0f0f0f; --panel: #1a1a1a; }
        body { background: var(--bg); color: #fff; height: 100vh; display: flex; overflow: hidden; margin: 0; }
        
        .sidebar { width: 350px; background: var(--panel); border-right: 1px solid #333; display: flex; flex-direction: column; z-index: 10; }
        .main-content { flex: 1; padding: 20px; background: #000; overflow-y: auto; }
        
        .group-header { background: #252525; color: #aaa; padding: 8px 15px; font-weight: bold; font-size: 12px; margin-top: 5px; }
        .video-item { padding: 10px 15px; border-bottom: 1px solid #222; cursor: pointer; transition: 0.2s; }
        .video-item:hover { background: #333; }
        .video-item.active { background: #3d3d3d; border-left: 4px solid var(--accent); }
        
        /* Video.js 布局修正 */
        .video-container { border-radius: 0; overflow: hidden; background: #000; position: relative; }
        
        .video-js .vjs-control-bar {
            display: flex !important;
            background: linear-gradient(transparent, rgba(0,0,0,0.8)) !important;
            height: 50px !important;
            padding: 0 10px !important;
            align-items: center;
        }

        .video-js .vjs-spacer {
            display: flex !important;
            flex: 1 1 auto !important;
        }

        .video-js .vjs-progress-control {
            position: absolute !important;
            width: 100% !important;
            height: 4px !important;
            top: -4px;
            left: 0;
            padding: 0 !important;
            margin: 0 !important;
        }
        .video-js .vjs-progress-control:hover { height: 8px !important; top: -8px; }

        .video-js .vjs-play-progress { background-color: var(--accent) !important; }
        .video-js .vjs-play-progress:before { display: block !important; }
        
        /* 按钮与文字垂直居中对齐补丁 */
        .vjs-button > .vjs-icon-placeholder:before { line-height: 50px !important; }
        .video-js .vjs-time-control { line-height: 50px !important; min-width: auto; padding: 0 5px; display: flex; align-items: center; }
        
        /* 专门针对倍速按钮的对齐 */
        .video-js .vjs-playback-rate { 
            line-height: 50px !important; 
            display: flex !important; 
            align-items: center !important; 
        }
        .video-js .vjs-playback-rate .vjs-playback-rate-value { 
            line-height: 50px !important; 
            display: block;
        }
        
        .inline-edit-panel { background: #222; border: 1px solid #444; padding: 15px; margin: 5px; border-radius: 8px; }
        .form-control { background: #333 !important; color: #fff !important; border: 1px solid #444 !important; }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: #444; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="p-3 d-flex justify-content-between align-items-center border-bottom border-secondary">
        <h6 class="m-0 fw-bold"><i class="bi bi-youtube text-danger me-2"></i>PRIVATE VOD</h6>
        <?php if($is_admin): ?>
            <a href="?action=logout" class="btn btn-dark btn-sm py-0 border-secondary" style="font-size:11px;">退出管理</a>
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
                         data-info='<?php echo json_encode($v); ?>' 
                         onclick="playVideo('<?php echo $v['url']; ?>', '<?php echo $v['title']; ?>', this)">
                        <div class="text-truncate" style="max-width: 220px;"><i class="bi bi-play-circle me-2"></i><?php echo htmlspecialchars($v['title']); ?></div>
                        <?php if($is_admin): ?>
                            <div class="small">
                                <i class="bi bi-pencil-square text-muted me-2" onclick="event.stopPropagation(); openInlineEdit('<?php echo $v['id']; ?>', this)"></i>
                                <a href="?delete_id=<?php echo $v['id']; ?>&from_group=<?php echo urlencode($gname); ?>" 
                                   class="text-muted" 
                                   onclick="event.stopPropagation(); return confirm('确认删除吗？')">
                                   <i class="bi bi-x-lg"></i>
                                </a>
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

<script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>
<script>
    const vPlayer = videojs('player', {
        fluid: true,
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
                'fullscreenToggle'
            ]
        }
    });

    function playVideo(url, title, el) {
        document.getElementById('v-title').innerText = title;
        document.getElementById('video-url-display').innerText = url;
        document.querySelectorAll('.video-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
        vPlayer.src({ src: url, type: 'application/x-mpegURL' });
        vPlayer.play();
    }

    function doSearch() {
        const kw = document.getElementById('search').value.toLowerCase();
        document.querySelectorAll('.video-container-unit').forEach(unit => {
            const title = unit.querySelector('.video-item').innerText.toLowerCase();
            unit.style.display = title.includes(kw) ? 'block' : 'none';
        });
    }

    function openInlineEdit(id, btn) {
        document.querySelectorAll('.inline-edit-panel').forEach(p => p.remove());
        const itemEl = btn.closest('.video-item');
        const data = JSON.parse(itemEl.getAttribute('data-info'));
        const slot = document.getElementById(`edit-slot-${id}`);
        const template = document.getElementById('edit-template').cloneNode(true);
        const form = template.querySelector('.inline-edit-panel');
        form.querySelector('.t-id').value = data.id;
        form.querySelector('.t-time').value = data.time || '';
        form.querySelector('.t-title').value = data.title;
        form.querySelector('.t-url').value = data.url;
        form.querySelector('.t-group').value = data.groups.join(', ');
        slot.appendChild(form);
        form.classList.remove('d-none');
    }

    function openInlineAdd() {
        const area = document.getElementById('inline-add-area');
        area.innerHTML = '';
        const template = document.getElementById('edit-template').cloneNode(true);
        const form = template.querySelector('.inline-edit-panel');
        area.appendChild(form);
        form.classList.remove('d-none');
    }

    function closeEdit(btn) { btn.closest('.inline-edit-panel').remove(); }
    function showLogin() { document.getElementById('loginPop').style.display = 'block'; }
    window.onclick = e => { if(e.target == document.getElementById('loginPop')) document.getElementById('loginPop').style.display = 'none'; }
</script>
</body>
</html>
