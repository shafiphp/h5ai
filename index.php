<?php
// ---------- CONFIG ----------
$BASE_DIR   = __DIR__;                 // e.g. '/mnt/media'
$SITE_LABEL = $_SERVER['HTTP_HOST'] ?? 'server';
$POWERED_BY = 'Network Service Provider(NSP)';
$HIDE       = ['index.php', '.htaccess', '.git', '$RECYCLE.BIN', 'System Volume Information','uploader.php','uploads'];
$MAX_SEARCH = 300;
// ----------------------------

date_default_timezone_set('Asia/Dhaka');
$BASE = realpath($BASE_DIR);

function clean_rel(string $p): string {
    $p = str_replace('\\', '/', $p);
    $parts = [];
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    return implode('/', $parts);
}

function resolve(string $rel) {
    global $BASE;
    $rel  = clean_rel($rel);
    $full = realpath($BASE . ($rel === '' ? '' : '/' . $rel));
    if ($full === false) return false;
    if ($full !== $BASE && strpos($full, $BASE . DIRECTORY_SEPARATOR) !== 0) return false;
    return [$full, $rel];
}

function hidden(string $name): bool {
    global $HIDE;
    return $name[0] === '.' || in_array($name, $HIDE, true);
}

function list_dir(string $full): array {
    $out = [];
    foreach (new DirectoryIterator($full) as $f) {
        if ($f->isDot()) continue;
        $n = $f->getFilename();
        if (hidden($n)) continue;
        $out[] = [
            'name'  => $n,
            'dir'   => $f->isDir(),
            'mtime' => $f->getMTime(),
            'size'  => $f->isDir() ? 0 : $f->getSize(),
        ];
    }
    return $out;
}

function has_subdirs(string $full): bool {
    try {
        foreach (new DirectoryIterator($full) as $f) {
            if (!$f->isDot() && $f->isDir() && !hidden($f->getFilename())) return true;
        }
    } catch (Throwable $e) {}
    return false;
}

function human(int $b): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float)$b;
    while ($v >= 1024 && $i < 4) { $v /= 1024; $i++; }
    return ($i === 0 ? (string)$b : number_format($v, $v >= 100 ? 0 : 1)) . ' ' . $u[$i];
}

function link_dir(string $rel): string { return '?p=' . rawurlencode($rel); }
function link_file(string $rel): string { return '?dl=' . rawurlencode($rel); }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// icon / link helpers shared by list view and grid view
function row_icon(array $f): string {
    if ($f['dir']) return 'folder';
    return preg_match('/\.(mkv|mp4|avi|mov|wmv|webm|m4v)$/i', $f['label']) ? 'video' : 'file';
}
function row_href(array $f): string {
    return $f['dir'] ? link_dir($f['path']) : link_file($f['path']);
}

// ---------- API: tree children ----------
if (($_GET['api'] ?? '') === 'tree') {
    header('Content-Type: application/json; charset=utf-8');
    $r = resolve($_GET['p'] ?? '');
    $res = [];
    if ($r && is_dir($r[0])) {
        $items = array_filter(list_dir($r[0]), fn($i) => $i['dir']);
        usort($items, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        foreach ($items as $i) {
            $path = ($r[1] === '' ? '' : $r[1] . '/') . $i['name'];
            $res[] = [
                'name' => $i['name'],
                'path' => $path,
                'kids' => has_subdirs($r[0] . '/' . $i['name']),
            ];
        }
    }
    echo json_encode($res);
    exit;
}

// ---------- Download / stream with Range support ----------
if (isset($_GET['dl'])) {
    $r = resolve($_GET['dl']);
    if (!$r || !is_file($r[0]) || hidden(basename($r[0]))) { http_response_code(404); exit('Not found'); }
    $file = $r[0];
    $size = filesize($file);
    $mime = function_exists('mime_content_type') ? (mime_content_type($file) ?: 'application/octet-stream') : 'application/octet-stream';
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $map  = ['mkv' => 'video/x-matroska', 'mp4' => 'video/mp4', 'avi' => 'video/x-msvideo', 'srt' => 'text/plain; charset=utf-8'];
    if (isset($map[$ext])) $mime = $map[$ext];

    $start = 0; $end = $size - 1;
    header('Accept-Ranges: bytes');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $size - (int)$m[2]);
        } else {
            if ($m[1] !== '') $start = (int)$m[1];
            if ($m[2] !== '') $end = min((int)$m[2], $end);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    $len = $end - $start + 1;
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $len);
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode(basename($file)));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    $fp = fopen($file, 'rb');
    fseek($fp, $start);
    while ($len > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, min(1024 * 256, $len));
        echo $chunk;
        $len -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit;
}

// ---------- Page ----------
$r = resolve($_GET['p'] ?? '');
if (!$r || !is_dir($r[0])) { http_response_code(404); $r = resolve(''); }
[$full, $rel] = $r;

$q     = trim($_GET['q'] ?? '');
$sort  = in_array($_GET['sort'] ?? '', ['name', 'mtime', 'size'], true) ? $_GET['sort'] : 'name';
$order = ($_GET['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$rows = [];
if ($q !== '') {
    // recursive search under the current folder
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS),
                fn($f) => !hidden($f->getFilename())
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if (stripos($f->getFilename(), $q) === false) continue;
            $sub = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($BASE))), '/');
            $rows[] = [
                'name' => $sub, 'label' => $f->getFilename(), 'dir' => $f->isDir(),
                'mtime' => $f->getMTime(), 'size' => $f->isDir() ? 0 : $f->getSize(),
                'path' => $sub,
            ];
            if (count($rows) >= $MAX_SEARCH) break;
        }
    } catch (Throwable $e) {}
} else {
    foreach (list_dir($full) as $i) {
        $i['label'] = $i['name'];
        $i['path']  = ($rel === '' ? '' : $rel . '/') . $i['name'];
        $rows[] = $i;
    }
}

usort($rows, function ($a, $b) use ($sort, $order) {
    if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1; // folders first
    switch ($sort) {
        case 'mtime': $c = $a['mtime'] <=> $b['mtime']; break;
        case 'size':  $c = $a['size']  <=> $b['size'];  break;
        default:      $c = strnatcasecmp($a['label'], $b['label']);
    }
    return $order === 'desc' ? -$c : $c;
});

// breadcrumb
$crumbs = [['label' => $SITE_LABEL, 'path' => '']];
$acc = '';
foreach ($rel === '' ? [] : explode('/', $rel) as $seg) {
    $acc .= ($acc === '' ? '' : '/') . $seg;
    $crumbs[] = ['label' => $seg, 'path' => $acc];
}
$parent = $rel === '' ? null : clean_rel(dirname($rel) === '.' ? '' : dirname($rel));

function sort_link(string $col, string $label, string $sort, string $order, string $rel, string $q): string {
    $next = ($sort === $col && $order === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $col ? ($order === 'asc' ? '▲' : '▼') : '';
    $url = '?p=' . rawurlencode($rel) . '&sort=' . $col . '&order=' . $next . ($q !== '' ? '&q=' . rawurlencode($q) : '');
    return '<a href="' . h($url) . '">' . h($label) . ' <small>' . $arrow . '</small></a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="https://cdn-icons-png.flaticon.com/512/1383/1383970.png">
<title><?= h(end($crumbs)['label']) ?> – <?= h($POWERED_BY) ?></title>
<script>
// apply saved view / tree choice before first paint (no flicker)
(function () {
  try {
    var d = document.documentElement;
    if (localStorage.getItem('fb_view') === 'grid') d.classList.add('view-grid');
    if (localStorage.getItem('fb_tree') === '0') d.classList.add('hidetree');
  } catch (e) {}
})();
</script>
<style>
:root{--bg:#fff;--text:#2b2b2b;--muted:#8a8a8a;--line:#ececec;--icon:#444;--hover:#f4f6f8;--bar:#fafafa}
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Roboto,Ubuntu,Arial,sans-serif;font-size:14px;color:var(--text);background:var(--bg)}
a{color:inherit;text-decoration:none}
svg.i{width:20px;height:20px;fill:var(--icon);flex:none}
svg.i.big{width:52px;height:52px}
header{display:flex;align-items:center;gap:14px;height:52px;padding:0 14px;background:var(--bar);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5}
header button{background:none;border:0;padding:4px;cursor:pointer;display:flex}
.crumbs{display:flex;align-items:center;gap:8px;flex:1;min-width:0;overflow:hidden;white-space:nowrap}
.crumbs a{overflow:hidden;text-overflow:ellipsis}
.crumbs .sep{color:var(--muted)}
.crumbs .cur{font-weight:600}
.powered{font-size:11px;color:#b0b0b0;text-align:right;line-height:1.2}
.searchbox{display:none;flex:1}
.searchbox input{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px}
body.searching .crumbs,body.searching .powered{display:none}
body.searching .searchbox{display:block}
/* dropdown menu */
.menuwrap{position:relative;display:flex}
.menupanel{display:none;position:absolute;top:42px;left:0;min-width:210px;background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:6px;z-index:20}
.menupanel.open{display:block}
.menupanel .lbl{font-size:11px;color:var(--muted);padding:6px 10px 2px;text-transform:uppercase;letter-spacing:.04em}
.menupanel button{display:flex;align-items:center;gap:10px;width:100%;padding:8px 10px;background:none;border:0;border-radius:6px;cursor:pointer;font:inherit;color:inherit;text-align:left}
.menupanel button:hover{background:var(--hover)}
.menupanel button .chk{margin-left:auto;visibility:hidden}
.menupanel button.on .chk{visibility:visible}
.menupanel hr{border:0;border-top:1px solid var(--line);margin:6px 0}
.layout{display:flex;min-height:calc(100vh - 52px)}
aside{width:320px;flex:none;padding:18px 10px;overflow:auto;border-right:1px solid transparent}
main{flex:1;min-width:0;padding:18px 16px}
/* tree */
.tree ul{list-style:none;margin:0;padding-left:22px}
.tree>ul{padding-left:0}
.tree .row{display:flex;align-items:center;gap:6px;padding:5px 4px;border-radius:4px;white-space:nowrap}
.tree .row:hover{background:var(--hover)}
.tree .row a{overflow:hidden;text-overflow:ellipsis;max-width:230px}
.tree .row.active a{font-weight:600}
.tree .arrow{width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#666;font-size:10px;transition:transform .15s;flex:none}
.tree .arrow.open{transform:rotate(90deg)}
.tree .arrow.none{visibility:hidden}
/* list view (table) */
table{width:100%;border-collapse:collapse}
th{font-weight:400;color:var(--muted);text-align:left;padding:8px 8px;font-size:13px}
th a small{font-size:9px}
td{padding:11px 8px;border-top:1px solid var(--line)}
tr.item:hover td{background:var(--hover)}
td.name a{display:flex;align-items:center;gap:14px}
td.name span{overflow:hidden;text-overflow:ellipsis;display:block;max-width:560px}
td.date,td.size,th.date,th.size{white-space:nowrap}
td.size,th.size{text-align:right;color:var(--muted)}
td.date{color:#555}
.empty{padding:30px;text-align:center;color:var(--muted)}
/* grid view */
.grid,.gridsort{display:none}
html.view-grid table{display:none}
html.view-grid .gridsort{display:block;padding:4px 4px 12px;color:var(--muted);font-size:13px}
html.view-grid .gridsort a{margin-right:12px}
html.view-grid .gridsort small{font-size:9px}
html.view-grid .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;padding:4px}
.card{display:flex;flex-direction:column;align-items:center;gap:8px;padding:16px 10px 12px;border:1px solid var(--line);border-radius:10px;text-align:center;min-width:0}
.card:hover{background:var(--hover)}
.card .nm{width:100%;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;word-break:break-word;font-weight:500;line-height:1.3}
.card .meta{font-size:12px;color:var(--muted)}
/* hide folder tree on desktop */
@media (min-width:761px){
  html.hidetree aside{display:none}
}
@media (max-width:760px){
  aside{position:fixed;inset:52px auto 0 0;background:#fff;z-index:4;transform:translateX(-100%);transition:transform .2s;box-shadow:2px 0 8px rgba(0,0,0,.15)}
  body.menu aside{transform:none}
  td.name span{max-width:44vw}
  th.size,td.size{display:none}
  main{padding:8px}
  html.view-grid .grid{grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px}
}
</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute">
  <symbol id="folder" viewBox="0 0 24 24"><path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/></symbol>
  <symbol id="file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></symbol>
  <symbol id="video" viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm6 4v8l6-4-6-4z"/></symbol>
  <symbol id="menu" viewBox="0 0 24 24"><path d="M3 6h18v2H3zm0 5h18v2H3zm0 5h18v2H3z"/></symbol>
  <symbol id="search" viewBox="0 0 24 24"><path d="M15.5 14h-.8l-.3-.3A6.5 6.5 0 1 0 14 15.5l.3.3v.8l5 5 1.5-1.5-5-5zm-6 0a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9z"/></symbol>
  <symbol id="back" viewBox="0 0 24 24"><path d="M20 11H7.8l5.6-5.6L12 4l-8 8 8 8 1.4-1.4L7.8 13H20z"/></symbol>
  <symbol id="root" viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm2 11h12v2H6z"/></symbol>
  <symbol id="list" viewBox="0 0 24 24"><path d="M4 6h2v2H4zm4 0h12v2H8zM4 11h2v2H4zm4 0h12v2H8zM4 16h2v2H4zm4 0h12v2H8z"/></symbol>
  <symbol id="gridicon" viewBox="0 0 24 24"><path d="M3 3h8v8H3zm10 0h8v8h-8zM3 13h8v8H3zm10 0h8v8h-8z"/></symbol>
  <symbol id="check" viewBox="0 0 24 24"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></symbol>
</svg>

<header>
  <div class="menuwrap">
    <button id="menuBtn" title="Menu" aria-haspopup="true" aria-expanded="false"><svg class="i"><use href="#menu"/></svg></button>
    <div class="menupanel" id="menuPanel" role="menu">
      <div class="lbl">View</div>
      <button type="button" data-view="list" role="menuitemradio"><svg class="i"><use href="#list"/></svg> List view <svg class="i chk"><use href="#check"/></svg></button>
      <button type="button" data-view="grid" role="menuitemradio"><svg class="i"><use href="#gridicon"/></svg> Grid view <svg class="i chk"><use href="#check"/></svg></button>
      <hr>
      <button type="button" id="treeBtn" role="menuitemcheckbox"><svg class="i"><use href="#folder"/></svg> Folder tree <svg class="i chk"><use href="#check"/></svg></button>
    </div>
  </div>
  <button id="searchBtn" title="Search"><svg class="i"><use href="#search"/></svg></button>

  <div class="crumbs">
    <?php foreach ($crumbs as $i => $c): $last = $i === count($crumbs) - 1; ?>
      <?php if ($i > 0): ?><span class="sep">›</span><?php endif; ?>
      <a href="<?= h(link_dir($c['path'])) ?>" class="<?= $last ? 'cur' : '' ?>"><?= h($c['label']) ?></a>
    <?php endforeach; ?>
  </div>

  <form class="searchbox" method="get">
    <input type="hidden" name="p" value="<?= h($rel) ?>">
    <input type="search" name="q" id="q" placeholder="Search in this folder…" value="<?= h($q) ?>">
  </form>

  <div class="powered">powered<br>by <?= h($POWERED_BY) ?></div>
</header>

<div class="layout">
  <aside>
    <div class="tree" id="tree"></div>
  </aside>

  <main>
    <!-- LIST VIEW -->
    <table>
      <thead>
        <tr>
          <th></th>
          <th><?= sort_link('name', 'Name', $sort, $order, $rel, $q) ?></th>
          <th class="date"><?= sort_link('mtime', 'Last modified', $sort, $order, $rel, $q) ?></th>
          <th class="size"><?= sort_link('size', 'Size', $sort, $order, $rel, $q) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($parent !== null): ?>
        <tr class="item">
          <td width="24"></td>
          <td class="name" colspan="3">
            <a href="<?= h(link_dir($parent)) ?>"><svg class="i"><use href="#back"/></svg>
              <span><?= h($parent === '' ? $SITE_LABEL : basename($parent)) ?></span></a>
          </td>
        </tr>
        <?php endif; ?>

        <?php foreach ($rows as $f): ?>
        <tr class="item">
          <td width="24"></td>
          <td class="name">
            <a href="<?= h(row_href($f)) ?>" title="<?= h($f['name']) ?>"><svg class="i"><use href="#<?= row_icon($f) ?>"/></svg>
              <span><?= h($q !== '' ? $f['name'] : $f['label']) ?></span></a>
          </td>
          <td class="date"><?= date('Y-m-d H:i:s', $f['mtime']) ?></td>
          <td class="size"><?= $f['dir'] ? '' : h(human($f['size'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- GRID VIEW -->
    <div class="gridsort">
      Sort by:
      <?= sort_link('name', 'Name', $sort, $order, $rel, $q) ?>
      <?= sort_link('mtime', 'Last modified', $sort, $order, $rel, $q) ?>
      <?= sort_link('size', 'Size', $sort, $order, $rel, $q) ?>
    </div>
    <div class="grid">
      <?php if ($parent !== null): ?>
        <a class="card" href="<?= h(link_dir($parent)) ?>">
          <svg class="i big"><use href="#back"/></svg>
          <span class="nm"><?= h($parent === '' ? $SITE_LABEL : basename($parent)) ?></span>
          <span class="meta">Parent folder</span>
        </a>
      <?php endif; ?>
      <?php foreach ($rows as $f): ?>
        <a class="card" href="<?= h(row_href($f)) ?>" title="<?= h($f['name']) ?>">
          <svg class="i big"><use href="#<?= row_icon($f) ?>"/></svg>
          <span class="nm"><?= h($q !== '' ? $f['name'] : $f['label']) ?></span>
          <span class="meta"><?= $f['dir'] ? '' : h(human($f['size'])) . ' · ' ?><?= date('Y-m-d', $f['mtime']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (!$rows): ?><div class="empty"><?= $q !== '' ? 'No matches.' : 'This folder is empty.' ?></div><?php endif; ?>
  </main>
</div>

<script>
const CURRENT = <?= json_encode($rel) ?>;
const SITE = <?= json_encode($SITE_LABEL) ?>;
const treeEl = document.getElementById('tree');

const icon = id => `<svg class="i"><use href="#${id}"/></svg>`;
const enc = p => '?p=' + encodeURIComponent(p);

async function fetchDirs(path) {
  const r = await fetch('?api=tree&p=' + encodeURIComponent(path));
  return r.json();
}

function makeNode(name, path, kids, active) {
  const li = document.createElement('li');
  li.dataset.path = path;
  li.innerHTML =
    `<div class="row${active ? ' active' : ''}">
       <span class="arrow ${kids ? '' : 'none'}">▶</span>
       ${icon(path === '' ? 'root' : 'folder')}
       <a href="${enc(path)}" title="${name.replace(/"/g, '&quot;')}"></a>
     </div><ul hidden></ul>`;
  li.querySelector('a').textContent = name;
  li.querySelector('.arrow').addEventListener('click', () => toggle(li));
  return li;
}

async function toggle(li, forceOpen) {
  const ul = li.querySelector(':scope > ul');
  const arrow = li.querySelector('.arrow');
  if (!forceOpen && !ul.hidden) { ul.hidden = true; arrow.classList.remove('open'); return; }
  if (!ul.dataset.loaded) {
    const dirs = await fetchDirs(li.dataset.path);
    dirs.forEach(d => ul.appendChild(makeNode(d.name, d.path, d.kids, d.path === CURRENT)));
    ul.dataset.loaded = '1';
  }
  ul.hidden = false;
  arrow.classList.add('open');
}

(async function initTree() {
  const rootUl = document.createElement('ul');
  const rootLi = makeNode(SITE, '', true, CURRENT === '');
  rootUl.appendChild(rootLi);
  treeEl.appendChild(rootUl);
  await toggle(rootLi, true);

  // expand down to the current folder
  let li = rootLi;
  let acc = '';
  for (const seg of CURRENT.split('/').filter(Boolean)) {
    acc += (acc ? '/' : '') + seg;
    const next = [...li.querySelectorAll(':scope > ul > li')].find(x => x.dataset.path === acc);
    if (!next) break;
    await toggle(next, true);
    li = next;
  }
  const act = treeEl.querySelector('.row.active');
  if (act) act.scrollIntoView({block: 'center'});
})();

// ---------- hamburger menu: list/grid view + folder tree ----------
const root      = document.documentElement;
const menuBtn   = document.getElementById('menuBtn');
const menuPanel = document.getElementById('menuPanel');
const treeBtn   = document.getElementById('treeBtn');
const isMobile  = () => window.matchMedia('(max-width:760px)').matches;
const store     = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };

function refreshMenu() {
  const view = root.classList.contains('view-grid') ? 'grid' : 'list';
  menuPanel.querySelectorAll('[data-view]').forEach(b => b.classList.toggle('on', b.dataset.view === view));
  const treeOn = isMobile() ? document.body.classList.contains('menu') : !root.classList.contains('hidetree');
  treeBtn.classList.toggle('on', treeOn);
}

function closeMenu() {
  menuPanel.classList.remove('open');
  menuBtn.setAttribute('aria-expanded', 'false');
}

menuBtn.addEventListener('click', e => {
  e.stopPropagation();
  refreshMenu();
  const open = menuPanel.classList.toggle('open');
  menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
});

menuPanel.querySelectorAll('[data-view]').forEach(b => {
  b.addEventListener('click', () => {
    root.classList.toggle('view-grid', b.dataset.view === 'grid');
    store('fb_view', b.dataset.view);
    refreshMenu();
    closeMenu();
  });
});

treeBtn.addEventListener('click', () => {
  if (isMobile()) {
    document.body.classList.toggle('menu');
  } else {
    root.classList.toggle('hidetree');
    store('fb_tree', root.classList.contains('hidetree') ? '0' : '1');
  }
  refreshMenu();
  closeMenu();
});

document.addEventListener('click', e => {
  if (!menuPanel.contains(e.target) && e.target !== menuBtn) closeMenu();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });

// search button
document.getElementById('searchBtn').onclick = () => {
  document.body.classList.toggle('searching');
  if (document.body.classList.contains('searching')) document.getElementById('q').focus();
};
if (new URLSearchParams(location.search).get('q')) document.body.classList.add('searching');

refreshMenu();
</script>
</body>
</html>
