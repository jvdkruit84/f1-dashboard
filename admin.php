<?php
session_start();

/* ---------- config ---------- */
// The password lives in admin-config.php, which is gitignored and never
// committed. If that file is missing, fail safe (no default password).
$configFile = __DIR__ . '/admin-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    die('admin-config.php ontbreekt. Upload dit bestand apart via FTP (zie instructies).');
}
require $configFile;
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
function sortPinnedFirst($articles) {
    usort($articles, function ($a, $b) {
        $pa = !empty($a['pinned']) ? 1 : 0;
        $pb = !empty($b['pinned']) ? 1 : 0;
        return $pb <=> $pa;
    });
    return $articles;
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
            $imageWarning = null;
            $uploadErr = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;

            if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
                $imageWarning = 'Afbeelding was groter dan de toegestane ' . ini_get('upload_max_filesize') . ' en is niet geüpload — placeholder gebruikt. Verklein de foto en voeg hem later opnieuw toe.';
            } elseif ($uploadErr !== UPLOAD_ERR_NO_FILE && $uploadErr !== UPLOAD_ERR_OK) {
                $imageWarning = 'Afbeelding kon niet worden geüpload (foutcode ' . $uploadErr . ') — placeholder gebruikt.';
            } elseif (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
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
                $pinned = isset($_POST['pinned']);
                $newArticle = [
                    'slug' => $slug,
                    'title' => $title,
                    'tag' => $tag,
                    'color' => $color,
                    'summary' => $summary,
                    'body' => $body,
                    'image' => $imagePath ?: (IMAGE_URL_PREFIX . '/placeholder.jpg'),
                    'date' => $date,
                    'pinned' => $pinned,
                ];
                if ($pinned) {
                    foreach ($articles as &$a) { $a['pinned'] = false; }
                    unset($a);
                }
                array_unshift($articles, $newArticle);
                $articles = sortPinnedFirst($articles);
                if (saveArticles($articles)) {
                    $message = 'Bericht geplaatst.' . ($imageWarning ? ' ' . $imageWarning : '');
                } else {
                    $message = 'Kon news.json niet opslaan — controleer de bestandsrechten op de server.';
                    $messageIsError = true;
                }
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $originalSlug = (string)($_POST['original_slug'] ?? '');
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
            $idx = null;
            foreach ($articles as $i => $a) {
                if (($a['slug'] ?? '') === $originalSlug) { $idx = $i; break; }
            }

            if ($idx === null) {
                $message = 'Bericht niet gevonden — mogelijk al verwijderd.';
                $messageIsError = true;
            } else {
                $imagePath = $articles[$idx]['image'] ?? (IMAGE_URL_PREFIX . '/placeholder.jpg');
                $imageWarning = null;
                $uploadErr = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;

                if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
                    $imageWarning = 'Nieuwe afbeelding was groter dan de toegestane ' . ini_get('upload_max_filesize') . ' — oude afbeelding behouden.';
                } elseif ($uploadErr !== UPLOAD_ERR_NO_FILE && $uploadErr !== UPLOAD_ERR_OK) {
                    $imageWarning = 'Nieuwe afbeelding kon niet worden geüpload (foutcode ' . $uploadErr . ') — oude afbeelding behouden.';
                } elseif (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
                    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
                    $origName = $_FILES['image']['name'];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
                    finfo_close($finfo);

                    if (isset($allowed[$ext]) && $allowed[$ext] === $mime) {
                        if (!is_dir(IMAGE_DIR)) mkdir(IMAGE_DIR, 0755, true);
                        $destFilename = $originalSlug . '.' . $ext;
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
                    $pinned = isset($_POST['pinned']);
                    if ($pinned) {
                        foreach ($articles as $i2 => &$a) {
                            if ($i2 !== $idx) $a['pinned'] = false;
                        }
                        unset($a);
                    }
                    $articles[$idx]['title'] = $title;
                    $articles[$idx]['tag'] = $tag;
                    $articles[$idx]['color'] = $color;
                    $articles[$idx]['summary'] = $summary;
                    $articles[$idx]['body'] = $body;
                    $articles[$idx]['date'] = $date;
                    $articles[$idx]['image'] = $imagePath;
                    $articles[$idx]['pinned'] = $pinned;
                    $articles = sortPinnedFirst($articles);
                    if (saveArticles($articles)) {
                        $message = 'Bericht bijgewerkt.' . ($imageWarning ? ' ' . $imageWarning : '');
                    } else {
                        $message = 'Kon news.json niet opslaan — controleer de bestandsrechten op de server.';
                        $messageIsError = true;
                    }
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
$editArticle = null;
if ($isAuthed && isset($_GET['edit'])) {
    foreach ($articles as $a) {
        if (($a['slug'] ?? '') === $_GET['edit']) { $editArticle = $a; break; }
    }
}
$pinSvg = '<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" style="vertical-align:-1px;"><path d="M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94.63 1.5 1.98 2.63 3.61 2.96V18H9v2h6v-2h-2v-2.1c1.63-.33 2.98-1.46 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z"/></svg>';
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
  .pin-row { display: flex; align-items: center; gap: 8px; margin-top: 16px; }
  .pin-row input[type=checkbox] { width: auto; }
  .pin-row span { font-size: 13px; color: var(--ink-primary); text-transform: none; font-weight: 400; letter-spacing: normal; }
  .pin-badge { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--warn, #ffb800); border: 1px solid currentColor; border-radius: 3px; padding: 1px 6px; margin-left: 8px; vertical-align: middle; }
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
    <h2><?= $editArticle ? 'Bericht bewerken' : 'Nieuw bericht' ?></h2>
    <form method="post" action="admin.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="<?= $editArticle ? 'edit' : 'add' ?>">
      <?php if ($editArticle): ?>
        <input type="hidden" name="original_slug" value="<?= htmlspecialchars($editArticle['slug']) ?>">
      <?php endif; ?>

      <label for="title">Titel</label>
      <input type="text" id="title" name="title" value="<?= htmlspecialchars($editArticle['title'] ?? '') ?>" required>

      <label for="summary">Korte samenvatting (op de homepage-kaart)</label>
      <input type="text" id="summary" name="summary" value="<?= htmlspecialchars($editArticle['summary'] ?? '') ?>">

      <label for="body">Volledige tekst (lege regel = nieuwe alinea)</label>
      <textarea id="body" name="body" required><?= htmlspecialchars($editArticle['body'] ?? '') ?></textarea>

      <label for="image">Afbeelding (jpg, png of webp)</label>
      <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
      <div class="hint">Max. <?= htmlspecialchars(ini_get('upload_max_filesize')) ?>. Is je foto groter? Verklein hem eerst (bijv. via de foto's-app van je telefoon of een gratis online tool).<?php if ($editArticle): ?> Laat leeg om de huidige afbeelding te behouden.<?php endif; ?></div>

      <label for="tag">Label</label>
      <input type="text" id="tag" name="tag" value="<?= htmlspecialchars($editArticle['tag'] ?? 'Nieuws') ?>">

      <label for="color">Kleur van het label</label>
      <input type="text" id="color" name="color" value="<?= htmlspecialchars($editArticle['color'] ?? '#e10600') ?>">
      <div class="hint">Bijvoorbeeld #e10600 (rood), #19a974 (groen), #ffb800 (geel).</div>

      <label for="date">Datum</label>
      <input type="date" id="date" name="date" value="<?= htmlspecialchars($editArticle['date'] ?? date('Y-m-d')) ?>">

      <label class="pin-row" for="pinned">
        <input type="checkbox" id="pinned" name="pinned" value="1" <?= !empty($editArticle['pinned']) ? 'checked' : '' ?>>
        <span><?= $pinSvg ?> Vastzetten bovenaan (altijd als eerste bericht tonen)</span>
      </label>

      <button type="submit"><?= $editArticle ? 'Opslaan' : 'Plaatsen' ?></button>
      <?php if ($editArticle): ?>
        <a href="admin.php" class="secondary" style="display:inline-block;margin-left:8px;margin-top:16px;padding:11px 20px;border:1px solid var(--border);border-radius:4px;color:var(--ink-secondary);text-decoration:none;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;font-size:13px;">Annuleren</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h2>Bestaande berichten (<?= count($articles) ?>)</h2>
    <?php if (!$articles): ?>
      <p style="color:var(--ink-muted); font-size:13px;">Nog geen berichten.</p>
    <?php else: foreach ($articles as $a): ?>
      <div class="article-row">
        <div>
          <div class="title"><?= htmlspecialchars($a['title'] ?? '') ?><?php if (!empty($a['pinned'])): ?><span class="pin-badge"><?= $pinSvg ?> Vastgezet</span><?php endif; ?></div>
          <div class="meta"><?= htmlspecialchars($a['date'] ?? '') ?> · <?= htmlspecialchars($a['slug'] ?? '') ?></div>
        </div>
        <div style="display:flex; gap:8px; flex:none;">
          <a href="admin.php?edit=<?= urlencode($a['slug'] ?? '') ?>" style="padding:6px 12px; font-size:11px; border:1px solid var(--border); color:var(--ink-secondary); border-radius:4px; text-decoration:none; text-transform:uppercase; letter-spacing:0.04em; font-weight:700;">Bewerken</a>
          <form method="post" onsubmit="return confirm('Dit bericht definitief verwijderen?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="slug" value="<?= htmlspecialchars($a['slug'] ?? '') ?>">
            <button type="submit">Verwijderen</button>
          </form>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

<?php endif; ?>

</div>
</body>
</html>
