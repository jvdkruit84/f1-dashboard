<?php
session_start();

/* ---------- config ---------- */
// Change this password whenever you like — it's only compared server-side.
define('ADMIN_PASSWORD', 'Q7GG6aDUYQoLhbRxCaA3');
define('NEWS_JSON', __DIR__ . '/news.json');
define('IMAGE_DIR', __DIR__ . '/images/news/custom');
define('IMAGE_URL_PREFIX', 'images/news/custom');

/* ---------- auth ---------- */
if (isset($_POST['login'])) {
    if (hash_equals(ADMIN_PASSWORD, (string)($_POST['password'] ?? ''))) {
        $_SESSION['f1hub_admin'] = true;
    } else {
        $loginError = 'Onjuist wachtwoord.';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}
$isAuthed = !empty($_SESSION['f1hub_admin']);

/* ---------- helpers ---------- */
function slugify($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text !== '' ? $text : 'bericht';
}
function loadArticles() {
    if (!file_exists(NEWS_JSON)) return [];
    $data = json_decode(file_get_contents(NEWS_JSON), true);
    return is_array($data) ? $data : [];
}
function saveArticles($articles) {
    return file_put_contents(NEWS_JSON, json_encode($articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

$message = null;
$messageIsError = false;

/* ---------- actions (auth required) ---------- */
if ($isAuthed && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $title = trim((string)($_POST['title'] ?? ''));
        $tag = trim((string)($_POST['tag'] ?? '')) ?: 'Nieuws';
        $color = trim((string)($_POST['color'] ?? '')) ?: '#e10600';
        $summary = trim((string)($_POST['summary'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $date = trim((string)($_POST['date'] ?? '')) ?: date('Y-m-d');

        if ($title === '' || $body === '') {
            $message = 'Titel en tekst zijn verplicht.';
            $messageIsError = true;
        } else {
            $articles = loadArticles();

            // unique slug
            $baseSlug = slugify($title);
            $slug = $baseSlug;
            $n = 2;
            $existingSlugs = array_column($articles, 'slug');
            while (in_array($slug, $existingSlugs, true)) {
                $slug = $baseSlug . '-' . $n;
                $n++;
            }

            $imagePath = null;
            if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
                $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
                $origName = $_FILES['image']['name'];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
                finfo_close($finfo);

                if (isset($allowed[$ext]) && $allowed[$ext] === $mime) {
                    if (!is_dir(IMAGE_DIR)) mkdir(IMAGE_DIR, 0755, true);
                    $destFilename = $slug . '.' . $ext;
                    $destPath = IMAGE_DIR . '/' . $destFilename;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $destPath)) {
                        $imagePath = IMAGE_URL_PREFIX . '/' . $destFilename;
                    }
                } else {
                    $message = 'Afbeelding moet een jpg, png of webp zijn.';
                    $messageIsError = true;
                }
            }

            if (!$messageIsError) {
                $newArticle = [
                    'slug' => $slug,
                    'title' => $title,
                    'tag' => $tag,
                    'color' => $color,
                    'summary' => $summary,
                    'body' => $body,
                    'image' => $imagePath ?: (IMAGE_URL_PREFIX . '/placeholder.jpg'),
                    'date' => $date,
                ];
                array_unshift($articles, $newArticle);
                if (saveArticles($articles)) {
                    $message = 'Bericht geplaatst.';
                } else {
                    $message = 'Kon news.json niet opslaan — controleer de bestandsrechten op de server.';
                    $messageIsError = true;
                }
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $slugToDelete = (string)($_POST['slug'] ?? '');
        $articles = loadArticles();
        $found = null;
        $articles = array_values(array_filter($articles, function ($a) use ($slugToDelete, &$found) {
            if (($a['slug'] ?? '') === $slugToDelete) { $found = $a; return false; }
            return true;
        }));
        if ($found) {
            if (saveArticles($articles)) {
                // best-effort cleanup of the image file, only within the custom news dir
                if (!empty($found['image']) && strpos($found['image'], IMAGE_URL_PREFIX . '/') === 0) {
                    $imgFile = __DIR__ . '/' . $found['image'];
                    $realImg = realpath($imgFile);
                    $realDir = realpath(IMAGE_DIR);
                    if ($realImg && $realDir && strpos($realImg, $realDir) === 0 && basename($realImg) !== 'placeholder.jpg') {
                        @unlink($realImg);
                    }
                }
                $message = 'Bericht verwijderd.';
            } else {
                $message = 'Kon news.json niet opslaan.';
                $messageIsError = true;
            }
        }
    }
}

$articles = $isAuthed ? loadArticles() : [];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Beheer — Formule1Hub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@400;600;700;900&display=swap" rel="stylesheet">
<style>
  :root {
    --surface-1: #15151e; --page: #0a0a0c; --ink-primary: #fff; --ink-secondary: #9a9aa2;
    --ink-muted: #6b6b72; --border: rgba(255,255,255,0.12); --accent: #e10600; --good: #19a974;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; font-family: 'Titillium Web', system-ui, sans-serif;
    background: var(--page); color: var(--ink-primary); padding: 24px;
  }
  .wrap { max-width: 720px; margin: 0 auto; }
  h1 { font-size: 22px; font-weight: 900; text-transform: uppercase; margin: 0 0 20px; }
  .card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 16px; }
  .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-secondary); margin: 0 0 14px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
  label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-secondary); margin: 12px 0 5px; }
  label:first-child { margin-top: 0; }
  input[type=text], input[type=date], input[type=password], textarea, input[type=file] {
    width: 100%; padding: 9px 12px; border-radius: 4px; border: 1px solid var(--border);
    background: var(--page); color: var(--ink-primary); font-family: inherit; font-size: 14px;
  }
  textarea { min-height: 140px; resize: vertical; }
  button { background: var(--accent); color: #fff; border: none; padding: 11px 20px; border-radius: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; font-size: 13px; cursor: pointer; margin-top: 16px; }
  button:hover { opacity: 0.9; }
  button.secondary { background: transparent; border: 1px solid var(--border); color: var(--ink-secondary); }
  .message { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
  .message.ok { background: rgba(25,169,116,0.15); color: var(--good); border: 1px solid var(--good); }
  .message.error { background: rgba(225,6,0,0.15); color: var(--accent); border: 1px solid var(--accent); }
  .article-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); gap: 12px; }
  .article-row:last-child { border-bottom: none; }
  .article-row .title { font-weight: 700; font-size: 14px; }
  .article-row .meta { font-size: 11px; color: var(--ink-muted); }
  .article-row form { margin: 0; }
  .article-row button { margin: 0; padding: 6px 12px; font-size: 11px; background: transparent; border: 1px solid var(--accent); color: var(--accent); }
  .logout { float: right; font-size: 12px; color: var(--ink-muted); text-decoration: none; }
  .hint { font-size: 11px; color: var(--ink-muted); margin-top: 6px; }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$isAuthed): ?>

  <h1>Formule1Hub beheer</h1>
  <div class="card">
    <?php if (!empty($loginError)): ?><div class="message error"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
    <form method="post">
      <label for="password">Wachtwoord</label>
      <input type="password" id="password" name="password" autofocus required>
      <button type="submit" name="login" value="1">Inloggen</button>
    </form>
  </div>

<?php else: ?>

  <a class="logout" href="admin.php?logout=1">Uitloggen</a>
  <h1>Formule1Hub beheer</h1>

  <?php if ($message): ?>
    <div class="message <?= $messageIsError ? 'error' : 'ok' ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Nieuw bericht</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <label for="title">Titel</label>
      <input type="text" id="title" name="title" required>

      <label for="summary">Korte samenvatting (op de homepage-kaart)</label>
      <input type="text" id="summary" name="summary">

      <label for="body">Volledige tekst (lege regel = nieuwe alinea)</label>
      <textarea id="body" name="body" required></textarea>

      <label for="image">Afbeelding (jpg, png of webp)</label>
      <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">

      <label for="tag">Label</label>
      <input type="text" id="tag" name="tag" value="Nieuws">

      <label for="color">Kleur van het label</label>
      <input type="text" id="color" name="color" value="#e10600">
      <div class="hint">Bijvoorbeeld #e10600 (rood), #19a974 (groen), #ffb800 (geel).</div>

      <label for="date">Datum</label>
      <input type="date" id="date" name="date" value="<?= date('Y-m-d') ?>">

      <button type="submit">Plaatsen</button>
    </form>
  </div>

  <div class="card">
    <h2>Bestaande berichten (<?= count($articles) ?>)</h2>
    <?php if (!$articles): ?>
      <p style="color:var(--ink-muted); font-size:13px;">Nog geen berichten.</p>
    <?php else: foreach ($articles as $a): ?>
      <div class="article-row">
        <div>
          <div class="title"><?= htmlspecialchars($a['title'] ?? '') ?></div>
          <div class="meta"><?= htmlspecialchars($a['date'] ?? '') ?> · <?= htmlspecialchars($a['slug'] ?? '') ?></div>
        </div>
        <form method="post" onsubmit="return confirm('Dit bericht definitief verwijderen?');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="slug" value="<?= htmlspecialchars($a['slug'] ?? '') ?>">
          <button type="submit">Verwijderen</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>

<?php endif; ?>

</div>
</body>
</html>
