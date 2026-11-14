<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

const DATA_FILE  = __DIR__ . '/data/gallery.json';
const LOCK_FILE  = __DIR__ . '/data/gallery.lock';
const UPLOAD_DIR = __DIR__ . '/uploads';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function json_out(array $a, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}
function load_gallery(): array {
  if (!is_file(DATA_FILE)) return ['updated' => 0, 'slots' => []];
  $g = json_decode((string)file_get_contents(DATA_FILE), true);
  return is_array($g) && isset($g['slots']) ? $g : ['updated' => 0, 'slots' => []];
}
function with_gallery(callable $fn) {
  if (!is_dir(dirname(DATA_FILE))) mkdir(dirname(DATA_FILE), 0755, true);
  $lock = fopen(LOCK_FILE, 'c');
  flock($lock, LOCK_EX);
  try {
    $g = load_gallery();
    $result = $fn($g);
    $g['updated'] = time();
    $tmp = DATA_FILE . '.tmp';
    file_put_contents($tmp, json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    rename($tmp, DATA_FILE);
    return $result;
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}
function resize_save($src, int $max, string $path, int $quality): void {
  $w = imagesx($src); $h = imagesy($src);
  $scale = min(1, $max / max($w, $h));
  $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
  $dst = imagecreatetruecolor($nw, $nh);
  imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
  imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
  imageinterlace($dst, true);
  if (!imagejpeg($dst, $path, $quality)) throw new RuntimeException('שמירת התמונה בשרת נכשלה. בדקו הרשאות כתיבה לתיקייה uploads.');
  imagedestroy($dst);
}

$authed = !empty($_SESSION['admin']);
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ---------- login / logout ---------- */
$loginError = '';
if (isset($_POST['login'])) {
  if (hash_equals(ADMIN_PASSWORD, (string)($_POST['password'] ?? ''))) {
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    header('Location: admin.php'); exit;
  }
  sleep(1);
  $loginError = 'הסיסמה לא נכונה. נסו שוב.';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

/* ---------- actions (JSON) ---------- */
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) json_out(['error' => 'פג תוקף הדף. רעננו ונסו שוב.'], 403);
  $slot = (string)($_POST['slot'] ?? '');
  if (!isset(SLOTS[$slot])) json_out(['error' => 'קטגוריה לא מוכרת.'], 400);
  $action = $_POST['action'];

  try {
    if ($action === 'upload') {
      if (!function_exists('imagecreatefromstring')) json_out(['error' => 'בשרת חסרה הרחבת GD של PHP. בקשו מחברת האחסון להפעיל אותה.'], 500);
      $f = $_FILES['photo'] ?? null;
      if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'ההעלאה נכשלה. ייתכן שהקובץ גדול מדי לשרת.'], 400);
      if ($f['size'] > 15 * 1024 * 1024) json_out(['error' => 'הקובץ גדול מ־15MB.'], 400);
      $info = @getimagesize($f['tmp_name']);
      if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) json_out(['error' => 'אפשר להעלות רק תמונות JPG, PNG או WEBP.'], 400);
      $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
      if (!$src) json_out(['error' => 'לא הצלחנו לקרוא את התמונה.'], 400);

      $dir = UPLOAD_DIR . '/' . $slot;
      if (!is_dir($dir) && !mkdir($dir, 0755, true)) json_out(['error' => 'אין הרשאה ליצור תיקייה ב־uploads.'], 500);
      $name = bin2hex(random_bytes(8));
      resize_save($src, MAX_SIDE, "$dir/$name.jpg", 85);
      resize_save($src, THUMB_SIDE, "$dir/{$name}_t.jpg", 80);
      imagedestroy($src);

      $item = ['f' => "uploads/$slot/$name.jpg", 't' => "uploads/$slot/{$name}_t.jpg"];
      $res = with_gallery(function (&$g) use ($slot, $item, $dir, $name) {
        $list = $g['slots'][$slot] ?? [];
        if (count($list) >= SLOTS[$slot]['max']) {
          if (SLOTS[$slot]['max'] === 1 && $list) { // single photo slot: replace
            foreach ($list as $old) { @unlink(__DIR__ . '/' . $old['f']); @unlink(__DIR__ . '/' . $old['t']); }
            $list = [];
          } else {
            @unlink("$dir/$name.jpg"); @unlink("$dir/{$name}_t.jpg");
            return ['error' => 'הגעתם למספר התמונות המקסימלי בקטגוריה הזו.'];
          }
        }
        $list[] = $item;
        $g['slots'][$slot] = $list;
        return ['ok' => true];
      });
      json_out($res, isset($res['error']) ? 400 : 200);
    }

    $i = (int)($_POST['i'] ?? -1);
    $res = with_gallery(function (&$g) use ($slot, $i, $action) {
      $list = $g['slots'][$slot] ?? [];
      if (!isset($list[$i])) return ['error' => 'התמונה לא נמצאה. רעננו את הדף.'];
      if ($action === 'delete') {
        @unlink(__DIR__ . '/' . $list[$i]['f']); @unlink(__DIR__ . '/' . $list[$i]['t']);
        array_splice($list, $i, 1);
      } elseif ($action === 'first') {
        $it = array_splice($list, $i, 1)[0]; array_unshift($list, $it);
      } elseif ($action === 'up' && $i > 0) {
        [$list[$i - 1], $list[$i]] = [$list[$i], $list[$i - 1]];
      } elseif ($action === 'down' && $i < count($list) - 1) {
        [$list[$i + 1], $list[$i]] = [$list[$i], $list[$i + 1]];
      }
      $g['slots'][$slot] = array_values($list);
      return ['ok' => true];
    });
    json_out($res, isset($res['error']) ? 400 : 200);
  } catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 500);
  }
}

$gallery = load_gallery();
?><!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>ניהול תמונות – לירז בוחניק</title>
<link href="https://fonts.googleapis.com/css2?family=Assistant:wght@400;600;700&family=Frank+Ruhl+Libre:wght@500&display=swap" rel="stylesheet">
<style>
:root{--paper:#fff;--mist:#E3EFE8;--ink:#3E3532;--soft:#6B5F5B;--sage:#4F7A66;--blush:#E9B7B9;--line:#DCE7E0;--danger:#A33A3A}
*{box-sizing:border-box}
body{margin:0;font:400 17px/1.6 "Assistant",Arial,sans-serif;background:#F6F9F7;color:var(--ink);padding-bottom:env(safe-area-inset-bottom,0px)}
h1,h2{font-family:"Frank Ruhl Libre",serif;font-weight:500;margin:0}
.top{position:sticky;top:0;z-index:5;background:var(--paper);border-bottom:1px solid var(--line);padding:calc(12px + env(safe-area-inset-top,0px)) 20px 12px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.top h1{font-size:22px}
.top a{color:var(--sage);font-weight:600;text-decoration:none}
main{max-width:1000px;margin:0 auto;padding:20px}
.slot{background:var(--paper);border:1px solid var(--line);border-radius:18px;padding:20px;margin-bottom:18px}
.slot-head{display:flex;justify-content:space-between;align-items:start;gap:12px;flex-wrap:wrap}
.slot h2{font-size:24px}
.hint{color:var(--soft);font-size:15px;margin:2px 0 0}
.count{font-size:14px;color:var(--soft)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-top:14px}
.item{position:relative;border-radius:12px;overflow:hidden;background:var(--mist);aspect-ratio:1}
.item img{width:100%;height:100%;object-fit:cover;display:block}
.item .badge{position:absolute;top:6px;inset-inline-start:6px;background:var(--blush);color:var(--ink);font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px}
.tools{position:absolute;inset:auto 0 0 0;display:flex;justify-content:space-between;gap:2px;padding:6px;background:rgba(62,53,50,.72)}
.tools button{flex:1;min-height:36px;border:0;border-radius:8px;background:rgba(255,255,255,.14);color:#fff;font:600 15px "Assistant",sans-serif;cursor:pointer}
.tools button:hover{background:rgba(255,255,255,.3)}
.tools .del{background:rgba(163,58,58,.85)}
.empty{color:var(--soft);font-size:15px;margin-top:12px}
.upload{display:inline-flex;align-items:center;gap:8px;background:var(--sage);color:#fff;border-radius:999px;padding:11px 22px;font-weight:700;cursor:pointer;min-height:44px}
.upload input{position:absolute;width:1px;height:1px;opacity:0}
.upload:focus-within{outline:2px solid var(--ink);outline-offset:3px}
.status{font-size:15px;margin-top:10px;min-height:1.4em}
.status.err{color:var(--danger);font-weight:600}
.login{max-width:380px;margin:12vh auto;background:var(--paper);border:1px solid var(--line);border-radius:20px;padding:32px}
.login h1{font-size:30px;margin-bottom:6px}
.login input{width:100%;font:17px "Assistant",sans-serif;padding:12px 14px;border:1px solid var(--line);border-radius:12px;margin:14px 0}
.login button{width:100%;background:var(--sage);color:#fff;border:0;border-radius:999px;padding:13px;font:700 17px "Assistant",sans-serif;cursor:pointer}
.error{color:var(--danger);font-weight:600}
@media (max-width:600px){main{padding:12px}.slot{padding:16px}.grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<?php if (!$authed): ?>
  <form class="login" method="post">
    <h1>ניהול תמונות</h1>
    <p class="hint">כניסה לאזור הניהול של האתר.</p>
    <?php if ($loginError): ?><p class="error" role="alert"><?= h($loginError) ?></p><?php endif; ?>
    <label for="pw">סיסמה</label>
    <input id="pw" type="password" name="password" autocomplete="current-password" required autofocus>
    <button type="submit" name="login" value="1">כניסה</button>
  </form>
<?php else: ?>
  <header class="top">
    <h1>ניהול תמונות</h1>
    <div><a href="./" target="_blank">צפייה באתר</a> &nbsp;·&nbsp; <a href="?logout=1">יציאה</a></div>
  </header>
  <main>
    <?php foreach (SLOTS as $id => $s): $list = $gallery['slots'][$id] ?? []; ?>
      <section class="slot" id="slot-<?= h($id) ?>">
        <div class="slot-head">
          <div>
            <h2><?= h($s['title']) ?></h2>
            <p class="hint"><?= h($s['hint']) ?></p>
            <span class="count"><?= count($list) ?> מתוך <?= (int)$s['max'] ?> תמונות</span>
          </div>
          <label class="upload">
            <?= $s['max'] === 1 ? 'החלפת תמונה' : 'הוספת תמונות' ?>
            <input type="file" accept="image/jpeg,image/png,image/webp" <?= $s['max'] > 1 ? 'multiple' : '' ?> data-slot="<?= h($id) ?>">
          </label>
        </div>
        <?php if (!$list): ?>
          <p class="empty">עדיין אין כאן תמונות. בינתיים יוצג באתר ריבוע צבעוני.</p>
        <?php else: ?>
          <div class="grid">
            <?php foreach ($list as $i => $it): ?>
              <div class="item">
                <img src="<?= h($it['t']) ?>" alt="" loading="lazy">
                <?php if ($i === 0 && $s['max'] > 1): ?><span class="badge"><?= $id === 'hero' ? 'קשת' : 'תמונת כרטיס' ?></span><?php endif; ?>
                <?php if ($i === 1 && $id === 'hero'): ?><span class="badge">עיגול</span><?php endif; ?>
                <div class="tools">
                  <?php if ($s['max'] > 1): ?>
                    <button type="button" data-act="up" data-slot="<?= h($id) ?>" data-i="<?= $i ?>" aria-label="הזזה אחורה">›</button>
                    <button type="button" data-act="first" data-slot="<?= h($id) ?>" data-i="<?= $i ?>" aria-label="העברה למקום הראשון">★</button>
                    <button type="button" data-act="down" data-slot="<?= h($id) ?>" data-i="<?= $i ?>" aria-label="הזזה קדימה">‹</button>
                  <?php endif; ?>
                  <button type="button" class="del" data-act="delete" data-slot="<?= h($id) ?>" data-i="<?= $i ?>" aria-label="מחיקה">✕</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <p class="status" role="status"></p>
      </section>
    <?php endforeach; ?>
  </main>

<script>
const CSRF = <?= json_encode($csrf) ?>;

async function post(data){
  const fd = new FormData();
  fd.append("csrf", CSRF);
  for (const [k,v] of Object.entries(data)) fd.append(k, v);
  const r = await fetch("admin.php", { method:"POST", body:fd, credentials:"same-origin" });
  let j = {}; try { j = await r.json(); } catch {}
  if (!r.ok || j.error) throw new Error(j.error || "השרת לא ענה. נסו שוב.");
  return j;
}

/* resize in the browser first: phone photos are big, and this also fixes rotation */
async function shrink(file, max = 2000){
  let bmp;
  try { bmp = await createImageBitmap(file, { imageOrientation:"from-image" }); }
  catch { bmp = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = URL.createObjectURL(file); }); }
  const w = bmp.width, h = bmp.height, s = Math.min(1, max / Math.max(w, h));
  const c = document.createElement("canvas");
  c.width = Math.round(w * s); c.height = Math.round(h * s);
  const ctx = c.getContext("2d");
  ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, c.width, c.height);
  ctx.drawImage(bmp, 0, 0, c.width, c.height);
  return await new Promise(res => c.toBlob(b => res(b || file), "image/jpeg", 0.88));
}

document.querySelectorAll('input[type=file]').forEach(inp => {
  inp.addEventListener("change", async () => {
    const files = [...inp.files]; if (!files.length) return;
    const status = inp.closest(".slot").querySelector(".status");
    status.classList.remove("err");
    let done = 0, failed = [];
    for (const f of files){
      status.textContent = `מעלה ${done + 1} מתוך ${files.length}…`;
      try { const blob = await shrink(f); await post({ action:"upload", slot:inp.dataset.slot, photo:new File([blob], "photo.jpg", { type:"image/jpeg" }) }); }
      catch (e) { failed.push(`${f.name}: ${e.message}`); }
      done++;
    }
    if (failed.length){ status.classList.add("err"); status.textContent = failed.join(" | "); setTimeout(() => location.reload(), 4000); }
    else { status.textContent = "התמונות הועלו."; location.hash = "slot-" + inp.dataset.slot; location.reload(); }
  });
});

document.addEventListener("click", async e => {
  const b = e.target.closest("button[data-act]"); if (!b) return;
  if (b.dataset.act === "delete" && !confirm("למחוק את התמונה מהאתר?")) return;
  const status = b.closest(".slot").querySelector(".status");
  try { await post({ action:b.dataset.act, slot:b.dataset.slot, i:b.dataset.i }); location.hash = "slot-" + b.dataset.slot; location.reload(); }
  catch (err) { status.classList.add("err"); status.textContent = err.message; }
});
</script>
<?php endif; ?>
</body>
</html>
