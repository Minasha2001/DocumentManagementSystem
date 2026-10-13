<?php
require __DIR__ . '/config.php';
$user = require_role($pdo, 'user');

const DEPARTMENTS = ['ආයතන', 'සභා', 'ගිණුම්'];

/* ------------------------------------------------------------------
   Search logic
   - නම (කොටසක් වුවත්), අංශය, විෂය අංකය, ලේකනාගාර අංකය
   - තෝරා ඇති සියලුම filters එකවර ගැලපිය යුතුය (AND)
   - subject officer විසින් archive කර ඇති (status = archived) ගොනු පමණි
------------------------------------------------------------------- */
function build_where(array $f): array {
    $where = ["status = 'archived'"];
    $p = [];
    if ($f['file_name'] !== '')      { $where[] = 'file_name LIKE ?';      $p[] = '%' . $f['file_name'] . '%'; }
    if ($f['department'] !== '')     { $where[] = 'department = ?';        $p[] = $f['department']; }
    if ($f['subject_number'] !== '') { $where[] = 'subject_number LIKE ?'; $p[] = '%' . $f['subject_number'] . '%'; }
    if ($f['archive_number'] !== '') { $where[] = 'archive_number LIKE ?'; $p[] = '%' . $f['archive_number'] . '%'; }
    return [implode(' AND ', $where), $p];
}

$filters = [
    'file_name'      => trim($_GET['file_name'] ?? ''),
    'department'     => in_array($_GET['department'] ?? '', DEPARTMENTS, true) ? $_GET['department'] : '',
    'subject_number' => trim($_GET['subject_number'] ?? ''),
    'archive_number' => trim($_GET['archive_number'] ?? ''),
];
[$whereSql, $whereParams] = build_where($filters);

/* ---------- Print view (සියලු ප්‍රතිඵල) ---------- */
if (isset($_GET['print'])) {
    $st = $pdo->prepare("SELECT * FROM archive_files WHERE $whereSql ORDER BY submit_date DESC, id DESC");
    $st->execute($whereParams);
    $all = $st->fetchAll();
    ?>
    <!DOCTYPE html><html lang="si"><head><meta charset="UTF-8"><title>ලිපිගොනු ලැයිස්තුව</title>
    <style>
        body{font-family:'Noto Sans Sinhala','Iskoola Pota',Arial,sans-serif;margin:30px;color:#111}
        h2{text-align:center;margin:0}.sub{text-align:center;color:#555;margin:4px 0 18px;font-size:13px}
        table{width:100%;border-collapse:collapse;font-size:12.5px}
        th,td{border:1px solid #888;padding:6px 8px;text-align:left}th{background:#eee}
    </style></head><body onload="window.print()">
    <h2>දකුණු පළාත් සභා ලේකම් කාර්යාලය</h2>
    <div class="sub">ලේකනාගාර ලිපිගොනු ලැයිස්තුව &nbsp;|&nbsp; මුද්‍රණය කළ දිනය: <?= date('Y-m-d') ?> &nbsp;|&nbsp; ගොනු <?= count($all) ?>ක්</div>
    <table>
        <tr><th>අංකය</th><th>ලිපිගොනුවේ නම</th><th>අංශය</th><th>විෂය අංකය</th><th>ලේකනාගාර අංකය</th><th>ලිපිගොනු අංකය</th><th>ආරම්භක දිනය</th><th>අවසන් දිනය</th><th>පිටු</th></tr>
        <?php foreach ($all as $i => $r): ?>
        <tr><td><?= $i + 1 ?></td><td><?= e($r['file_name']) ?></td><td><?= e($r['department']) ?></td>
            <td><?= e($r['subject_number']) ?></td><td><?= e($r['archive_number']) ?></td><td><?= e($r['file_number']) ?></td>
            <td><?= e($r['start_date']) ?></td><td><?= e($r['end_date']) ?></td><td><?= (int)$r['page_count'] ?></td></tr>
        <?php endforeach; ?>
    </table></body></html>
    <?php
    exit;
}

/* ---------- Tabs ---------- */
$tab = ($_GET['tab'] ?? 'search') === 'submit' ? 'submit' : 'search';

/* ------------------------------------------------------------------
   ලිපිගොනුවක් යොමු කිරීම (සුරකින්න)
------------------------------------------------------------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$errors = [];
$old = [
    'submit_date' => date('Y-m-d'), 'subject_number' => '', 'department' => '', 'file_number' => '',
    'file_name' => '', 'start_date' => '', 'end_date' => '', 'page_count' => '',
];

function valid_date($d) { $x = DateTime::createFromFormat('Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_file') {
    $tab = 'submit';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'ආරක්ෂක පරීක්ෂාව අසාර්ථකයි. පිටුව නැවත පූරණය කර උත්සාහ කරන්න.';
    }
    foreach ($old as $k => $_) $old[$k] = trim($_POST[$k] ?? '');

    if (!valid_date($old['submit_date']))           $errors[] = 'දිනය නිවැරදිව ඇතුළත් කරන්න.';
    if ($old['subject_number'] === '')              $errors[] = 'විෂය අංකය ඇතුළත් කරන්න.';
    if (!in_array($old['department'], DEPARTMENTS, true)) $errors[] = 'අංශය තෝරන්න.';
    if ($old['file_number'] === '')                 $errors[] = 'ලිපිගොනු අංකය ඇතුළත් කරන්න.';
    if ($old['file_name'] === '')                   $errors[] = 'ලිපිගොනුවේ නම ඇතුළත් කරන්න.';
    if (!valid_date($old['start_date']))            $errors[] = 'ආරම්භක දිනය නිවැරදිව ඇතුළත් කරන්න.';
    if (!valid_date($old['end_date']))              $errors[] = 'අවසන් කළ දිනය නිවැරදිව ඇතුළත් කරන්න.';
    if (valid_date($old['start_date']) && valid_date($old['end_date']) && $old['end_date'] < $old['start_date'])
                                                    $errors[] = 'අවසන් දිනය ආරම්භක දිනයට පෙර විය නොහැක.';
    if (!ctype_digit($old['page_count']) || (int)$old['page_count'] < 1) $errors[] = 'පිටු ගණන නිවැරදිව ඇතුළත් කරන්න.';

    if (!$errors) {
        $ins = $pdo->prepare(
            "INSERT INTO archive_files
             (submitted_by, submit_date, subject_number, department, file_number, file_name, start_date, end_date, page_count, status)
             VALUES (?,?,?,?,?,?,?,?,?, 'pending')"
        );
        $ins->execute([
            $user['id'], $old['submit_date'], $old['subject_number'], $old['department'], $old['file_number'],
            $old['file_name'], $old['start_date'], $old['end_date'], (int)$old['page_count'],
        ]);
        $_SESSION['flash'] = 'ලිපිගොනුව සාර්ථකව සුරකින ලදී. එය දැන් විෂය නිලධාරියාට පෙන්වනු ඇත.';
        header('Location: user_dashboard.php?tab=submit'); exit;   // PRG - refresh කළ විට නැවත save නොවේ
    }
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ---------- Search results + pagination ---------- */
$perPage = 10;
$cnt = $pdo->prepare("SELECT COUNT(*) FROM archive_files WHERE $whereSql");
$cnt->execute($whereParams);
$total  = (int)$cnt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT * FROM archive_files WHERE $whereSql ORDER BY submit_date DESC, id DESC LIMIT $perPage OFFSET $offset");
$st->execute($whereParams);
$rows = $st->fetchAll();

/* ---------- මගේ යොමු කිරීම් ---------- */
$mine = $pdo->prepare('SELECT * FROM archive_files WHERE submitted_by = ? ORDER BY created_at DESC LIMIT 8');
$mine->execute([$user['id']]);
$mine = $mine->fetchAll();

function page_url(int $p): string { $q = $_GET; $q['page'] = $p; $q['tab'] = 'search'; return '?' . http_build_query($q); }
function print_url(): string { $q = $_GET; unset($q['page'], $q['tab']); $q['print'] = 1; return '?' . http_build_query($q); }
$statusLabel = ['pending' => ['විෂය නිලධාරී බලාපොරොත්තුවෙන්', 'st-pending'], 'archived' => ['ලේඛනාගාරගත කළා', 'st-ok'], 'rejected' => ['ප්‍රතික්ෂේප කළා', 'st-no']];
?>
<!DOCTYPE html>
<html lang="si">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>පරිශීලක උපකරණ පුවරුව - ලේකනාගාර පද්ධතිය</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Sinhala:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--navy:#0f2a5c;--blue:#1668e3;--blue-d:#0f55c4;--bg:#f3f6fb;--line:#dfe5ee;--text:#1c2740;--muted:#6b778c;--ok:#1b8a4b;--warn:#b7791f;--err:#c0392b}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter','Noto Sans Sinhala',sans-serif;background:var(--bg);color:var(--text);font-size:14px;display:flex;min-height:100vh}
a{text-decoration:none;color:inherit}

/* Sidebar */
.sidebar{width:276px;flex-shrink:0;background:linear-gradient(180deg,#112d63,#0b1f4d);color:#fff;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
.brand{display:flex;gap:14px;padding:20px 18px 26px}
.brand .crest{width:46px;height:54px;border-radius:50% 50% 45% 45%;background:linear-gradient(145deg,#e0b93b,#a8801a);flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#7a1d1d;font-size:22px}
.brand h1{font-size:16px;line-height:1.35;font-weight:700}
.brand p{font-size:12.5px;color:#b9c6e2;margin-top:4px}
.nav{padding:0 12px;display:flex;flex-direction:column;gap:4px}
.nav a{display:flex;align-items:center;gap:14px;padding:12px 14px;border-radius:8px;font-size:14.5px;color:#e3eaf8;font-weight:500}
.nav a i{width:22px;text-align:center;font-size:17px}
.nav a:hover{background:rgba(255,255,255,.08)}
.nav a.active{background:#27498f;color:#fff}
.nav .sep{height:1px;background:rgba(255,255,255,.12);margin:10px 6px}
.tagline{margin-top:auto;padding:0 24px 30px;font-size:13px;color:#9fb0d6;line-height:1.6}

/* Topbar */
.main{flex:1;min-width:0}
.topbar{height:64px;background:#fff;border-bottom:1px solid #eef1f6;display:flex;align-items:center;justify-content:space-between;padding:0 24px}
.hamburger{display:none;background:none;border:0;font-size:20px;cursor:pointer}
.user{display:flex;align-items:center;gap:12px;margin-left:auto}
.avatar{width:40px;height:40px;border-radius:50%;background:#dbe3ef;display:flex;align-items:center;justify-content:center;color:#2a3550;font-size:18px}
.user .nm{font-weight:600;line-height:1.2}.user .rl{font-size:12px;color:var(--muted)}

.content{padding:22px 26px 44px}
.page-head{display:flex;align-items:center;gap:18px;margin-bottom:18px}
.page-head .ico{width:58px;height:58px;border-radius:50%;background:var(--blue);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 6px 16px rgba(22,104,227,.28)}
.page-head h2{font-size:24px;color:#12285a}.page-head p{color:#4a5873;margin-top:3px}

/* Tabs */
.tabs{display:flex;gap:6px;margin-bottom:16px;border-bottom:1px solid var(--line)}
.tabs a{padding:12px 20px;font-weight:600;color:#55627c;border-bottom:3px solid transparent;margin-bottom:-1px;display:flex;align-items:center;gap:9px}
.tabs a.on{color:var(--blue);border-color:var(--blue)}
.tabs a:hover{color:var(--blue)}

.card{background:#fff;border:1px solid #eef1f6;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(20,40,90,.05);margin-bottom:20px}
.card h3{display:flex;align-items:center;gap:12px;font-size:18px;color:#14224a}
.card h3 i{color:var(--blue)}

/* Forms */
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px 20px;margin-top:20px}
.grid.two{grid-template-columns:repeat(2,1fr)}
.field label{display:block;font-weight:600;font-size:13.5px;margin-bottom:7px}
.field label .req{color:var(--err)}
.input{position:relative}
.input>i.lead{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#5a6781;font-size:15px}
.input input,.input select{width:100%;height:42px;border:1px solid var(--line);border-radius:7px;padding:0 12px 0 40px;font:inherit;color:var(--text);background:#fff;outline:none;transition:.15s}
.input select{appearance:none;-webkit-appearance:none;padding-right:34px;cursor:pointer}
.input .chev{position:absolute;right:13px;top:50%;transform:translateY(-50%);font-size:11px;pointer-events:none}
.input input::placeholder{color:#8f9aae}
.input input:focus,.input select:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(22,104,227,.13)}
.actions{display:flex;gap:10px;margin-top:22px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;height:40px;padding:0 22px;border-radius:7px;font:inherit;font-weight:600;cursor:pointer;border:1px solid transparent;transition:.15s}
.btn-primary{background:var(--blue);color:#fff}.btn-primary:hover{background:var(--blue-d)}
.btn-outline{background:#fff;border-color:var(--line);color:#2a3550}.btn-outline:hover{background:#f5f8fd}
.btn-green{background:var(--ok);color:#fff}.btn-green:hover{background:#157640}

/* Alerts */
.alert{padding:13px 16px;border-radius:8px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-start}
.alert-ok{background:#e9f7ef;border:1px solid #bfe5cf;color:#14633a}
.alert-err{background:#fdecea;border:1px solid #f5c6c0;color:#8e271d}
.alert ul{margin-left:18px}

/* Table */
.res-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.count{color:#4a5873;margin:16px 0 12px}
.table-wrap{overflow-x:auto;border:1px solid #eef1f6;border-radius:8px}
table{width:100%;border-collapse:collapse;min-width:900px}
thead th{background:#f1f4f9;text-align:left;font-weight:600;padding:13px 12px;white-space:nowrap}
tbody td{padding:11px 12px;border-top:1px solid #eef1f6}
tbody tr:hover{background:#fafcff}
.fname{font-weight:600;color:#14224a}
.chip{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#e8f0fe;color:#1a4fb3}
.st-pending{background:#fff4dc;color:var(--warn)}.st-ok{background:#e6f6ec;color:var(--ok)}.st-no{background:#fdecea;color:var(--err)}
.btn-view{height:32px;padding:0 16px;background:var(--blue);color:#fff;border-radius:6px;font-weight:500;font-size:13px}
.empty{text-align:center;padding:42px 10px;color:var(--muted)}
.empty i{font-size:34px;display:block;margin-bottom:10px;color:#b7c2d8}
.pager{display:flex;gap:6px;margin-top:16px;flex-wrap:wrap}
.pager a,.pager span{min-width:34px;height:34px;display:flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:6px;background:#fff;font-weight:500}
.pager .cur{background:var(--blue);border-color:var(--blue);color:#fff}.pager .dis{color:#b4bccb}
.pager a:hover{background:#f1f5fc}
.hint{color:var(--muted);font-size:12.5px;margin-top:6px}

@media(max-width:1000px){
    .sidebar{position:fixed;z-index:30;transform:translateX(-100%);transition:transform .2s}
    body.menu-open .sidebar{transform:none}
    .hamburger{display:block}
    .grid,.grid.two{grid-template-columns:1fr}
    .content{padding:18px 14px}
}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">
        <div class="crest"><i class="fa-solid fa-landmark"></i></div>
        <div>
            <h1>දකුණු පළාත් සභා<br>ලේකම් කාර්යාලය</h1>
            <p>ලේකනාගාර ලිපිගොනු පද්ධතිය</p>
        </div>
    </div>
    <nav class="nav">
        <a href="user_dashboard.php" class="active"><i class="fa-solid fa-house"></i> උපකරණ පුවරුව</a>
        <a href="profile.php"><i class="fa-solid fa-user"></i> මගේ පැතිකඩ</a>
        <a href="change_password.php"><i class="fa-solid fa-lock"></i> මුරපදය වෙනස් කිරීම</a>
        <div class="sep"></div>
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> ඉවත් වන්න</a>
    </nav>
    <div class="tagline">කාර්යක්ෂම ලේඛන,<br>සුබ හෙට දිනයක් උදෙසා</div>
</aside>

<div class="main">
    <header class="topbar">
        <button class="hamburger" onclick="document.body.classList.toggle('menu-open')" aria-label="මෙනුව"><i class="fa-solid fa-bars"></i></button>
        <div class="user">
            <div class="avatar"><i class="fa-solid fa-user"></i></div>
            <div><div class="nm"><?= e($user['full_name']) ?></div><div class="rl">පරිශීලක</div></div>
        </div>
    </header>

    <main class="content">
        <div class="page-head">
            <div class="ico"><i class="fa-regular fa-folder-open"></i></div>
            <div><h2>පරිශීලක උපකරණ පුවරුව</h2><p>ලේකනාගාර ලිපිගොනු සොයන්න සහ ලිපිගොනු යොමු කරන්න</p></div>
        </div>

        <div class="tabs">
            <a href="?tab=search" class="<?= $tab === 'search' ? 'on' : '' ?>"><i class="fa-solid fa-magnifying-glass"></i> ලිපිගොනු සෙවීම</a>
            <a href="?tab=submit" class="<?= $tab === 'submit' ? 'on' : '' ?>"><i class="fa-solid fa-file-circle-plus"></i> ලිපිගොනුවක් යොමු කිරීම</a>
        </div>

<?php if ($tab === 'search'): ?>
        <!-- ================= SEARCH ================= -->
        <section class="card">
            <h3><i class="fa-solid fa-magnifying-glass"></i> ලිපිගොනු සොයන්න</h3>
            <form method="get">
                <input type="hidden" name="tab" value="search">
                <div class="grid">
                    <div class="field">
                        <label>ලිපිගොනුවේ නම</label>
                        <div class="input"><i class="fa-regular fa-file-lines lead"></i>
                            <input type="text" name="file_name" value="<?= e($filters['file_name']) ?>" placeholder="නමේ කොටසක් වුවද ඇතුළත් කරන්න...">
                        </div>
                    </div>
                    <div class="field">
                        <label>අංශය</label>
                        <div class="input"><i class="fa-solid fa-landmark lead"></i>
                            <select name="department">
                                <option value="">අංශය තෝරන්න</option>
                                <?php foreach (DEPARTMENTS as $d): ?>
                                    <option value="<?= e($d) ?>" <?= $filters['department'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fa-solid fa-chevron-down chev"></i>
                        </div>
                    </div>
                    <div class="field">
                        <label>විෂය අංකය</label>
                        <div class="input"><i class="fa-solid fa-hashtag lead"></i>
                            <input type="text" name="subject_number" value="<?= e($filters['subject_number']) ?>" placeholder="විෂය අංකය ඇතුළත් කරන්න...">
                        </div>
                    </div>
                    <div class="field">
                        <label>ලේකනාගාර අංකය</label>
                        <div class="input"><i class="fa-solid fa-box-archive lead"></i>
                            <input type="text" name="archive_number" value="<?= e($filters['archive_number']) ?>" placeholder="ලේකනාගාර අංකය ඇතුළත් කරන්න...">
                        </div>
                    </div>
                </div>
                <div class="actions">
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> සොයන්න</button>
                    <a class="btn btn-outline" href="?tab=search"><i class="fa-solid fa-rotate"></i> මකන්න</a>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="res-head">
                <h3><i class="fa-regular fa-rectangle-list"></i> සෙවුම් ප්‍රතිඵල</h3>
                <?php if ($total > 0): ?>
                <a class="btn btn-primary" href="<?= e(print_url()) ?>" target="_blank"><i class="fa-solid fa-print"></i> ප්‍රතිඵල මුද්‍රණය</a>
                <?php endif; ?>
            </div>
            <div class="count">ලිපිගොනු <strong><?= $total ?></strong>ක් හමු විය</div>

            <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>අංකය</th><th>ලිපිගොනුවේ නම</th><th>අංශය</th><th>විෂය අංකය</th><th>ලේකනාගාර අංකය</th>
                    <th>ආරම්භක දිනය</th><th>අවසන් දිනය</th><th>පිටු</th><th>ක්‍රියා</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9"><div class="empty"><i class="fa-regular fa-folder-open"></i>ගැලපෙන ලිපිගොනු හමු නොවීය. වෙනත් නමක් හෝ අංකයක් උත්සාහ කරන්න.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td class="fname"><?= e($r['file_name']) ?></td>
                        <td><span class="chip"><?= e($r['department']) ?></span></td>
                        <td><?= e($r['subject_number']) ?></td>
                        <td><?= e($r['archive_number']) ?></td>
                        <td><?= e($r['start_date']) ?></td>
                        <td><?= e($r['end_date']) ?></td>
                        <td><?= (int)$r['page_count'] ?></td>
                        <td><a class="btn btn-view" href="view_file.php?id=<?= (int)$r['id'] ?>"><i class="fa-regular fa-eye"></i> බලන්න</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($pages > 1): ?>
            <div class="pager">
                <?php if ($page > 1): ?><a href="<?= e(page_url($page - 1)) ?>"><i class="fa-solid fa-chevron-left"></i></a>
                <?php else: ?><span class="dis"><i class="fa-solid fa-chevron-left"></i></span><?php endif; ?>
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <?php if ($p === $page): ?><span class="cur"><?= $p ?></span>
                    <?php else: ?><a href="<?= e(page_url($p)) ?>"><?= $p ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $pages): ?><a href="<?= e(page_url($page + 1)) ?>"><i class="fa-solid fa-chevron-right"></i></a>
                <?php else: ?><span class="dis"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
            </div>
            <?php endif; ?>
        </section>

<?php else: ?>
        <!-- ================= SUBMIT FILE ================= -->
        <?php if ($flash): ?>
            <div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i><div><?= e($flash) ?></div></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-err"><i class="fa-solid fa-triangle-exclamation"></i>
                <div>කරුණාකර පහත දෝෂ නිවැරදි කරන්න:<ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
            </div>
        <?php endif; ?>

        <section class="card">
            <h3><i class="fa-solid fa-file-circle-plus"></i> ලේඛනාගාරයට ලිපිගොනුවක් යොමු කරන්න</h3>
            <form method="post" action="user_dashboard.php?tab=submit">
                <input type="hidden" name="action" value="submit_file">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <div class="grid">
                    <div class="field">
                        <label>දිනය <span class="req">*</span></label>
                        <div class="input"><i class="fa-regular fa-calendar lead"></i>
                            <input type="date" name="submit_date" value="<?= e($old['submit_date']) ?>" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>විෂය අංකය <span class="req">*</span></label>
                        <div class="input"><i class="fa-solid fa-hashtag lead"></i>
                            <input type="text" name="subject_number" value="<?= e($old['subject_number']) ?>" placeholder="උදා: SUB-001" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>අංශය <span class="req">*</span></label>
                        <div class="input"><i class="fa-solid fa-landmark lead"></i>
                            <select name="department" required>
                                <option value="">අංශය තෝරන්න</option>
                                <?php foreach (DEPARTMENTS as $d): ?>
                                    <option value="<?= e($d) ?>" <?= $old['department'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fa-solid fa-chevron-down chev"></i>
                        </div>
                    </div>
                    <div class="field">
                        <label>ලිපිගොනු අංකය <span class="req">*</span></label>
                        <div class="input"><i class="fa-regular fa-address-card lead"></i>
                            <input type="text" name="file_number" value="<?= e($old['file_number']) ?>" placeholder="ලිපිගොනු අංකය" required>
                        </div>
                    </div>
                    <div class="field" style="grid-column:span 2">
                        <label>ලිපිගොනුවේ නම <span class="req">*</span></label>
                        <div class="input"><i class="fa-regular fa-file-lines lead"></i>
                            <input type="text" name="file_name" value="<?= e($old['file_name']) ?>" placeholder="ලිපිගොනුවේ සම්පූර්ණ නම" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>ලිපිගොනුවේ ආරම්භක දිනය <span class="req">*</span></label>
                        <div class="input"><i class="fa-regular fa-calendar-plus lead"></i>
                            <input type="date" name="start_date" value="<?= e($old['start_date']) ?>" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>ලිපිගොනුව අවසන් කළ දිනය <span class="req">*</span></label>
                        <div class="input"><i class="fa-regular fa-calendar-check lead"></i>
                            <input type="date" name="end_date" value="<?= e($old['end_date']) ?>" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>ගොනුවේ පිටු ගණන <span class="req">*</span></label>
                        <div class="input"><i class="fa-regular fa-copy lead"></i>
                            <input type="number" min="1" name="page_count" value="<?= e($old['page_count']) ?>" placeholder="පිටු ගණන" required>
                        </div>
                    </div>
                </div>
                <p class="hint">සුරකිනු ලැබූ පසු ලිපිගොනුව විෂය නිලධාරියාට පෙන්වන අතර, ලේකනාගාර අංකය ලබා දෙනු ලබන්නේ ඔහු විසිනි.</p>
                <div class="actions">
                    <button class="btn btn-green" type="submit"><i class="fa-solid fa-floppy-disk"></i> සුරකින්න</button>
                    <button class="btn btn-outline" type="reset"><i class="fa-solid fa-rotate"></i> හිස් කරන්න</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> මා විසින් යොමු කළ ලිපිගොනු</h3>
            <div class="table-wrap" style="margin-top:16px">
            <table style="min-width:760px">
                <thead><tr><th>දිනය</th><th>ලිපිගොනුවේ නම</th><th>අංශය</th><th>විෂය අංකය</th><th>පිටු</th><th>තත්ත්වය</th></tr></thead>
                <tbody>
                <?php if (!$mine): ?>
                    <tr><td colspan="6"><div class="empty"><i class="fa-regular fa-paper-plane"></i>තවම ලිපිගොනු යොමු කර නැත.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($mine as $m): [$lbl, $cls] = $statusLabel[$m['status']]; ?>
                    <tr>
                        <td><?= e($m['submit_date']) ?></td>
                        <td class="fname"><?= e($m['file_name']) ?></td>
                        <td><span class="chip"><?= e($m['department']) ?></span></td>
                        <td><?= e($m['subject_number']) ?></td>
                        <td><?= (int)$m['page_count'] ?></td>
                        <td><span class="chip <?= $cls ?>"><?= e($lbl) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
<?php endif; ?>
    </main>
</div>
</body>
</html>