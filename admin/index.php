<?php
session_start();

define('CONFIG_DIR',           dirname(__DIR__) . '/config');
define('CONTENT_DIR',          dirname(__DIR__) . '/content');
define('ADMIN_CONFIG',         CONFIG_DIR . '/admin.json');
define('ADMIN_CONFIG_EXAMPLE', CONFIG_DIR . '/admin.json_example');

// ── Helpers ──────────────────────────────────────────────────────────────────

function loadAdminConfig(): array {
    // If the live config doesn't exist yet, bootstrap it from the example template.
    // This means a fresh deployment never overwrites an existing password.
    if (!file_exists(ADMIN_CONFIG)) {
        if (file_exists(ADMIN_CONFIG_EXAMPLE)) {
            copy(ADMIN_CONFIG_EXAMPLE, ADMIN_CONFIG);
        } else {
            // Fallback: write a blank template so setup can proceed
            file_put_contents(ADMIN_CONFIG, json_encode(['password_hash' => ''], JSON_PRETTY_PRINT));
        }
    }
    return json_decode(file_get_contents(ADMIN_CONFIG), true) ?? [];
}

function saveAdminConfig(array $data): void {
    file_put_contents(ADMIN_CONFIG, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function verifyCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitizeFilename(string $name): string {
    $name = pathinfo(basename($name), PATHINFO_FILENAME);
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    $name = trim($name, '_');
    return ($name ?: 'exam') . '.sef';
}

function getSefFiles(): array {
    if (!is_dir(CONTENT_DIR)) return [];
    $files = [];
    foreach (scandir(CONTENT_DIR) as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sef') {
            $path = CONTENT_DIR . '/' . $file;
            $files[] = [
                'name'     => $file,
                'basename' => pathinfo($file, PATHINFO_FILENAME),
                'size'     => filesize($path),
                'modified' => filemtime($path),
            ];
        }
    }
    usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $files;
}

function fmtSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    return round($bytes / 1024, 1) . ' KB';
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$adminConfig  = loadAdminConfig();
$passwordHash = $adminConfig['password_hash'] ?? '';
$needsSetup   = empty($passwordHash);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$isAuthenticated = !empty($_SESSION['admin_authenticated']);
$action = $_REQUEST['action'] ?? '';
$message = '';
$messageType = '';

// ── Handle setup (first run) ───────────────────────────────────────────────

if ($needsSetup && $action === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token.'; $messageType = 'error';
    } elseif (strlen($_POST['password'] ?? '') < 6) {
        $message = 'Password must be at least 6 characters.'; $messageType = 'error';
    } elseif (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? '')) {
        $message = 'Passwords do not match.'; $messageType = 'error';
    } else {
        $adminConfig['password_hash'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
        saveAdminConfig($adminConfig);
        $passwordHash = $adminConfig['password_hash'];
        $needsSetup = false;
        $_SESSION['admin_authenticated'] = true;
        header('Location: index.php');
        exit;
    }
}

// ── Handle login ───────────────────────────────────────────────────────────

if (!$needsSetup && !$isAuthenticated && $action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token.'; $messageType = 'error';
    } elseif (password_verify($_POST['password'] ?? '', $passwordHash)) {
        $_SESSION['admin_authenticated'] = true;
        header('Location: index.php');
        exit;
    } else {
        $message = 'Incorrect password.'; $messageType = 'error';
    }
}

// ── Handle logout ──────────────────────────────────────────────────────────

if ($action === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ── All actions below require authentication ──────────────────────────────

// ── Change password ────────────────────────────────────────────────────────

if ($isAuthenticated && $action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token.'; $messageType = 'error';
    } elseif (!password_verify($_POST['current_password'] ?? '', $passwordHash)) {
        $message = 'Current password is incorrect.'; $messageType = 'error';
    } elseif (strlen($_POST['new_password'] ?? '') < 6) {
        $message = 'New password must be at least 6 characters.'; $messageType = 'error';
    } elseif (($_POST['new_password'] ?? '') !== ($_POST['new_password_confirm'] ?? '')) {
        $message = 'New passwords do not match.'; $messageType = 'error';
    } else {
        $adminConfig['password_hash'] = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
        saveAdminConfig($adminConfig);
        $message = 'Password updated successfully.'; $messageType = 'success';
    }
}

// ── Save file ──────────────────────────────────────────────────────────────

if ($isAuthenticated && $action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token.'; $messageType = 'error';
    } else {
        $filename    = sanitizeFilename($_POST['filename'] ?? 'exam');
        $fileContent = str_replace("\r\n", "\n", $_POST['content'] ?? '');
        $filePath    = CONTENT_DIR . '/' . $filename;
        if (!is_dir(CONTENT_DIR)) mkdir(CONTENT_DIR, 0755, true);
        if (file_put_contents($filePath, $fileContent) !== false) {
            $base = pathinfo($filename, PATHINFO_FILENAME);
            header('Location: index.php?action=edit&file=' . urlencode($base) . '&msg=saved');
            exit;
        } else {
            $message = "Could not write {$filename} — check directory permissions.";
            $messageType = 'error';
        }
    }
}

// ── Delete file ────────────────────────────────────────────────────────────

if ($isAuthenticated && $action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token.'; $messageType = 'error';
    } else {
        $filename = sanitizeFilename($_POST['filename'] ?? '');
        $filePath = CONTENT_DIR . '/' . $filename;
        if (file_exists($filePath) && unlink($filePath)) {
            $base = pathinfo($filename, PATHINFO_FILENAME);
            header('Location: index.php?msg=deleted&f=' . urlencode($base));
            exit;
        } else {
            $message = "Could not delete {$filename}."; $messageType = 'error';
        }
    }
}

// ── Redirect messages ──────────────────────────────────────────────────────

if (!$message) {
    if (($_GET['msg'] ?? '') === 'saved') {
        $message = 'Saved successfully.'; $messageType = 'success';
    } elseif (($_GET['msg'] ?? '') === 'deleted') {
        $message = 'Deleted: ' . htmlspecialchars($_GET['f'] ?? '') . '.sef'; $messageType = 'success';
    }
}

// ── Determine view ─────────────────────────────────────────────────────────

if ($needsSetup)          $view = 'setup';
elseif (!$isAuthenticated) $view = 'login';
elseif (in_array($action, ['new', 'edit'])) $view = 'editor';
elseif ($action === 'change_password') $view = 'change_password';
elseif ($action === 'help')   $view = 'help';
elseif ($action === 'prompt') $view = 'prompt';
else                          $view = 'list';

// ── Load file for editing ──────────────────────────────────────────────────

$editContent  = '';
$editFilename = '';
if ($view === 'editor') {
    $fileParam = trim($_GET['file'] ?? '');
    if ($fileParam) {
        $editFilename = pathinfo(sanitizeFilename($fileParam), PATHINFO_FILENAME);
        $filePath = CONTENT_DIR . '/' . $editFilename . '.sef';
        if (file_exists($filePath)) {
            $editContent = file_get_contents($filePath);
        }
    }
}

$pageTitle = 'Smart Exam Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #121212;
            color: #e0e0e0;
            min-height: 100vh;
        }

        /* ── Nav ── */
        .navbar {
            background: #1e1e1e;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #333;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .navbar-brand {
            font-size: 1.1em;
            font-weight: bold;
            color: #03dac6;
            text-decoration: none;
        }
        .navbar-right { display: flex; gap: 10px; align-items: center; }

        /* ── Buttons ── */
        .btn {
            display: inline-block;
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-primary   { background: #6200ee; color: #fff; }
        .btn-primary:hover { background: #3700b3; }
        .btn-success   { background: #2ecc71; color: #fff; }
        .btn-success:hover { background: #27ae60; }
        .btn-danger    { background: #e74c3c; color: #fff; }
        .btn-danger:hover  { background: #c0392b; }
        .btn-secondary { background: #444; color: #e0e0e0; }
        .btn-secondary:hover { background: #555; }
        .btn-sm { padding: 5px 12px; font-size: 0.82em; }

        /* ── Main layout ── */
        .main { padding: 24px; max-width: 1100px; margin: 0 auto; }

        /* ── Card / panels ── */
        .card {
            background: #1e1e1e;
            border-radius: 10px;
            padding: 28px 32px;
            box-shadow: 0 4px 12px rgba(0,0,0,.3);
        }
        .card h2 { margin-top: 0; color: #03dac6; }

        /* ── Auth screens ── */
        .auth-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .auth-card {
            background: #1e1e1e;
            border-radius: 10px;
            padding: 36px 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 6px 20px rgba(0,0,0,.5);
        }
        .auth-card h2 { margin-top: 0; color: #03dac6; text-align: center; }

        /* ── Forms ── */
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.9em;
            color: #b0b0b0;
        }
        .form-control {
            width: 100%;
            padding: 9px 12px;
            border-radius: 5px;
            border: 1px solid #444;
            background: #2c2c2c;
            color: #e0e0e0;
            font-size: 0.95em;
        }
        .form-control:focus { outline: none; border-color: #6200ee; }
        .form-row { display: flex; gap: 10px; align-items: center; }
        .form-row .form-control { flex: 1; }
        .suffix { color: #888; font-size: 0.9em; white-space: nowrap; }

        /* ── Alerts ── */
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 18px;
            font-size: 0.9em;
        }
        .alert-success { background: #1a3a2a; color: #4caf50; border: 1px solid #2e7d32; }
        .alert-error   { background: #3a1a1a; color: #f44336; border: 1px solid #7d2e2e; }
        .alert-info    { background: #1a2a3a; color: #64b5f6; border: 1px solid #1565c0; }

        /* ── File list ── */
        .file-table { width: 100%; border-collapse: collapse; }
        .file-table th {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 2px solid #333;
            color: #888;
            font-size: 0.85em;
            font-weight: normal;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .file-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #2a2a2a;
            vertical-align: middle;
        }
        .file-table tr:last-child td { border-bottom: none; }
        .file-table tr:hover td { background: #252525; }
        .file-name { color: #03dac6; font-weight: bold; }
        .file-meta { color: #888; font-size: 0.85em; }
        .actions { display: flex; gap: 6px; }

        /* ── Editor ── */
        .editor-panels {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 16px;
        }
        @media (max-width: 700px) {
            .editor-panels { grid-template-columns: 1fr; }
        }
        .panel { display: flex; flex-direction: column; gap: 10px; }
        .panel h3 { margin: 0 0 6px; font-size: 1em; color: #b0b0b0; }
        .panel textarea {
            flex: 1;
            min-height: 320px;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #444;
            background: #1a1a1a;
            color: #e0e0e0;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            resize: vertical;
            line-height: 1.5;
        }
        .panel textarea:focus { outline: none; border-color: #6200ee; }
        #question-input { background: #222; }
        #exam-content   { background: #1a1a1a; }
        .panel-buttons  { display: flex; gap: 8px; flex-wrap: wrap; }
        .hint {
            font-size: 0.78em;
            color: #666;
            margin: 0;
            line-height: 1.5;
        }
        .hint code {
            background: #2c2c2c;
            padding: 1px 5px;
            border-radius: 3px;
            color: #03dac6;
        }
        .editor-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }
        .editor-toolbar .form-row { flex: 1; min-width: 200px; }

        /* ── Breadcrumb ── */
        .breadcrumb {
            font-size: 0.85em;
            color: #888;
            margin-bottom: 20px;
        }
        .breadcrumb a { color: #03dac6; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .breadcrumb span { color: #555; margin: 0 6px; }

        /* ── Change password form ── */
        .settings-card { max-width: 460px; }

        /* ── Empty state ── */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        .empty-state code {
            background: #2c2c2c;
            padding: 2px 6px;
            border-radius: 3px;
            color: #03dac6;
        }

        /* ── Help / docs ── */
        .help-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        @media (max-width: 700px) { .help-grid { grid-template-columns: 1fr; } }

        .help-section h3 {
            margin: 0 0 10px;
            color: #03dac6;
            font-size: 1em;
            border-bottom: 1px solid #2a2a2a;
            padding-bottom: 8px;
        }
        .help-section p, .help-section ul, .help-section ol {
            font-size: 0.9em;
            color: #c0c0c0;
            line-height: 1.7;
            margin: 0 0 10px;
            padding-left: 18px;
        }
        .help-section p { padding-left: 0; }

        .sef-example {
            background: #141414;
            border: 1px solid #333;
            border-radius: 6px;
            padding: 14px 16px;
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            line-height: 1.7;
            color: #e0e0e0;
            white-space: pre;
            overflow-x: auto;
            margin: 10px 0 0;
        }
        .sef-q  { color: #e0e0e0; }
        .sef-ok { color: #4caf50; font-weight: bold; }
        .sef-no { color: #888; }
        .sef-comment { color: #555; font-style: italic; }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.78em;
            font-weight: bold;
            vertical-align: middle;
        }
        .tag-correct  { background: #1b3a1e; color: #4caf50; }
        .tag-wrong    { background: #2a1a1a; color: #e57373; }

        .ref-box {
            margin-top: 24px;
            padding: 16px 20px;
            background: #1a2030;
            border: 1px solid #2a3a55;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .ref-box-text { flex: 1; min-width: 200px; }
        .ref-box-text h3 { margin: 0 0 4px; color: #64b5f6; font-size: 0.95em; }
        .ref-box-text p  { margin: 0; font-size: 0.85em; color: #90a0b0; }
        .ref-box a.btn   { white-space: nowrap; }

        /* ── Prompt helper ── */
        .prompt-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 6px;
        }
        @media (max-width: 700px) { .prompt-grid { grid-template-columns: 1fr; } }
        .prompt-grid .full-width { grid-column: 1 / -1; }

        .prompt-output-wrap {
            margin-top: 24px;
            display: none;
        }
        .prompt-output-wrap.visible { display: block; }
        .prompt-output-wrap h3 {
            margin: 0 0 10px;
            color: #03dac6;
            font-size: 1em;
        }
        .prompt-output-textarea {
            width: 100%;
            min-height: 340px;
            padding: 14px;
            background: #141414;
            border: 1px solid #333;
            border-radius: 6px;
            color: #d0d0d0;
            font-family: 'Courier New', monospace;
            font-size: 0.82em;
            line-height: 1.6;
            resize: vertical;
            box-sizing: border-box;
        }
        .prompt-copy-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 10px;
        }
        .prompt-copied {
            font-size: 0.85em;
            color: #4caf50;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .prompt-copied.show { opacity: 1; }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23888'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 28px;
        }
    </style>
</head>
<body>

<?php if ($view === 'setup'): ?>
<!-- ════════════════════════════════════════════════════════════
     SETUP VIEW
════════════════════════════════════════════════════════════ -->
<div class="auth-wrapper">
    <div class="auth-card">
        <h2>Admin Setup</h2>
        <p style="text-align:center;color:#888;font-size:.9em;margin-top:-8px;">Set an admin password to get started.</p>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <input type="hidden" name="action" value="setup">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="form-group">
                <label>Password</label>
                <input class="form-control" type="password" name="password" required autofocus>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input class="form-control" type="password" name="password_confirm" required>
            </div>
            <button class="btn btn-primary" style="width:100%;margin-top:8px;" type="submit">Set Password &amp; Enter Admin</button>
        </form>
    </div>
</div>

<?php elseif ($view === 'login'): ?>
<!-- ════════════════════════════════════════════════════════════
     LOGIN VIEW
════════════════════════════════════════════════════════════ -->
<div class="auth-wrapper">
    <div class="auth-card">
        <h2>Admin Login</h2>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="form-group">
                <label>Password</label>
                <input class="form-control" type="password" name="password" required autofocus>
            </div>
            <button class="btn btn-primary" style="width:100%;margin-top:8px;" type="submit">Login</button>
        </form>
        <p style="text-align:center;margin-top:18px;">
            <a href="../index.php" style="color:#03dac6;font-size:.85em;">← Back to Exam Simulator</a>
        </p>
    </div>
</div>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════
     AUTHENTICATED VIEWS — shared navbar
════════════════════════════════════════════════════════════ -->
<nav class="navbar">
    <a class="navbar-brand" href="index.php">Smart Exam Admin</a>
    <div class="navbar-right">
        <a href="../index.php" class="btn btn-secondary btn-sm">View Site</a>
        <a href="index.php?action=help" class="btn btn-secondary btn-sm">SEF Format</a>
        <a href="index.php?action=prompt" class="btn btn-secondary btn-sm">Prompt Helper</a>
        <a href="index.php?action=change_password" class="btn btn-secondary btn-sm">Change Password</a>
        <a href="index.php?action=logout" class="btn btn-danger btn-sm">Logout</a>
    </div>
</nav>

<div class="main">

<?php if ($view === 'list'): ?>
<!-- ── FILE LIST ─────────────────────────────────────────── -->
    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h2 style="margin:0;">Exam Files</h2>
            <a href="index.php?action=new" class="btn btn-primary">+ New Exam</a>
        </div>

        <?php $sefFiles = getSefFiles(); ?>
        <?php if (empty($sefFiles)): ?>
        <div class="empty-state">
            <p>No <code>.sef</code> files found in <code>content/</code>.</p>
            <a href="index.php?action=new" class="btn btn-primary">Create your first exam</a>
        </div>
        <?php else: ?>
        <table class="file-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Size</th>
                    <th>Last Modified</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sefFiles as $f): ?>
                <tr>
                    <td><span class="file-name"><?= htmlspecialchars($f['basename']) ?></span><span class="file-meta">.sef</span></td>
                    <td class="file-meta"><?= fmtSize($f['size']) ?></td>
                    <td class="file-meta"><?= date('Y-m-d H:i', $f['modified']) ?></td>
                    <td>
                        <div class="actions">
                            <a href="index.php?action=edit&file=<?= urlencode($f['basename']) ?>" class="btn btn-secondary btn-sm">Edit</a>
                            <form method="post" action="index.php" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($f['name'])) ?>?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="filename" value="<?= htmlspecialchars($f['basename']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php elseif ($view === 'editor'): ?>
<!-- ── EDITOR ───────────────────────────────────────────── -->
    <div class="breadcrumb">
        <a href="index.php">Exam Files</a>
        <span>›</span>
        <?= $editFilename ? htmlspecialchars($editFilename) . '.sef' : 'New Exam' ?>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="post" action="index.php" id="save-form">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="editor-toolbar">
                <div class="form-row" style="flex:1;max-width:360px;">
                    <input class="form-control" type="text" name="filename" id="filename-input"
                           placeholder="exam-name"
                           value="<?= htmlspecialchars($editFilename) ?>" required>
                    <span class="suffix">.sef</span>
                </div>
                <button type="submit" class="btn btn-success">Save to /content/</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>

            <div class="editor-panels">
                <!-- Left: Question Builder -->
                <div class="panel">
                    <h3>Question Builder</h3>
                    <textarea id="question-input"
                              placeholder="Type the question on the first line, then one answer per line:

What is the capital of France?
Paris
Berlin
Madrid
Rome"></textarea>
                    <div class="panel-buttons">
                        <button type="button" class="btn btn-secondary" onclick="parseQuestion()">1. Parse</button>
                        <button type="button" class="btn btn-primary" onclick="addQuestion()">2. Add to Exam &rarr;</button>
                        <button type="button" class="btn btn-secondary" onclick="clearBuilder()" title="Clear question builder">Clear</button>
                    </div>
                    <p class="hint">
                        <b>Parse</b> adds <code>-</code> prefixes to answer lines automatically.<br>
                        Change <code>-</code> to <code>-*</code> on correct answers, then click <b>Add to Exam</b>.<br>
                        Multiple <code>-*</code> lines = multiple-correct (checkbox) question.
                    </p>
                </div>

                <!-- Right: Exam Content -->
                <div class="panel">
                    <h3>Exam Content (.sef)</h3>
                    <textarea name="content" id="exam-content"><?= htmlspecialchars($editContent) ?></textarea>
                    <div class="panel-buttons">
                        <button type="button" class="btn btn-secondary" onclick="clearExam()" title="Clear exam content">Clear All</button>
                        <button type="button" class="btn btn-secondary" onclick="copyExam()">Copy to Clipboard</button>
                    </div>
                    <p class="hint">
                        This is the raw <code>.sef</code> file content — edit directly or use the Question Builder on the left.
                        Questions are separated by blank lines.
                    </p>
                </div>
            </div>

        </form>
    </div>

<?php elseif ($view === 'change_password'): ?>
<!-- ── CHANGE PASSWORD ──────────────────────────────────── -->
    <div class="breadcrumb">
        <a href="index.php">Exam Files</a>
        <span>›</span> Change Password
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card settings-card">
        <h2>Change Password</h2>
        <form method="post" action="index.php">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="form-group">
                <label>Current Password</label>
                <input class="form-control" type="password" name="current_password" required autofocus>
            </div>
            <div class="form-group">
                <label>New Password <span style="color:#666;font-size:.85em;">(min 6 characters)</span></label>
                <input class="form-control" type="password" name="new_password" required>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input class="form-control" type="password" name="new_password_confirm" required>
            </div>
            <div style="display:flex;gap:10px;margin-top:20px;">
                <button class="btn btn-primary" type="submit">Update Password</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<?php elseif ($view === 'help'): ?>
<!-- ── SEF FORMAT HELP ────────────────────────────────────── -->
    <div class="breadcrumb">
        <a href="index.php">Exam Files</a>
        <span>›</span> SEF Format Help
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Smart Exam Format (.sef)</h2>
        <p style="color:#b0b0b0;font-size:.95em;margin-bottom:0;">
            A plain-text format for writing multiple-choice exam questions.
            Each <code style="background:#2c2c2c;padding:1px 5px;border-radius:3px;color:#03dac6;">.sef</code> file
            is a simple text document — no special tools required to create or edit it.
        </p>

        <div class="help-grid">

            <!-- Basic structure -->
            <div class="help-section">
                <h3>Basic Structure</h3>
                <p>Each question block starts with the question text on its own line, followed by answer options. Blocks are separated by a blank line.</p>
                <div class="sef-example"><span class="sef-q">What is the capital of France?</span>
<span class="sef-ok">-* Paris</span>
<span class="sef-no">-  Berlin</span>
<span class="sef-no">-  Madrid</span>
<span class="sef-no">-  Rome</span>

<span class="sef-q">Which language runs in a browser?</span>
<span class="sef-ok">-* JavaScript</span>
<span class="sef-no">-  Python</span>
<span class="sef-no">-  PHP</span></div>
            </div>

            <!-- Answer prefixes -->
            <div class="help-section">
                <h3>Answer Prefixes</h3>
                <p>Each answer line must start with one of two prefixes:</p>
                <ul>
                    <li><code>-*</code> &nbsp;<span class="tag tag-correct">correct</span> — this answer is right</li>
                    <li><code>-</code> &nbsp;&nbsp;<span class="tag tag-wrong">wrong</span> — this answer is a distractor</li>
                </ul>
                <p>The text after the prefix (and optional space) is the answer label shown to the student.</p>
                <div class="sef-example"><span class="sef-ok">-* Correct answer text</span>
<span class="sef-no">-  Wrong answer text</span>
<span class="sef-no">-  Another wrong answer</span></div>
            </div>

            <!-- Multiple correct answers -->
            <div class="help-section">
                <h3>Multiple Correct Answers</h3>
                <p>Mark more than one answer with <code>-*</code> to create a <em>select-all-that-apply</em> question. The simulator automatically switches to checkboxes instead of radio buttons.</p>
                <div class="sef-example"><span class="sef-q">Which are primary colors?</span>
<span class="sef-ok">-* Red</span>
<span class="sef-ok">-* Blue</span>
<span class="sef-ok">-* Yellow</span>
<span class="sef-no">-  Green</span>
<span class="sef-no">-  Purple</span></div>
            </div>

            <!-- Images & links -->
            <div class="help-section">
                <h3>Images &amp; Links</h3>
                <p>Both question text and answer text support inline markdown-style links. When clicked, images open in a lightbox overlay.</p>
                <div class="sef-example"><span class="sef-q">What does this diagram show?
<span style="color:#64b5f6;">[View diagram](images/diagram.png)</span></span>
<span class="sef-ok">-* A binary search tree</span>
<span class="sef-no">-  A linked list</span></div>
                <p style="margin-top:10px;">Syntax: <code style="background:#2c2c2c;padding:1px 5px;border-radius:3px;color:#03dac6;">[label](url)</code> — the URL can be relative or absolute.</p>
            </div>

            <!-- Whitespace rules -->
            <div class="help-section">
                <h3>Whitespace Rules</h3>
                <ul>
                    <li>Blank lines <strong>between</strong> question blocks are required.</li>
                    <li>Blank lines <strong>within</strong> a question block are ignored.</li>
                    <li>Leading and trailing whitespace on each line is stripped.</li>
                    <li>The file should be saved as <strong>UTF-8</strong> plain text.</li>
                </ul>
            </div>

            <!-- URL loading -->
            <div class="help-section">
                <h3>URL Parameters</h3>
                <p>The simulator supports loading an exam directly via URL:</p>
                <ul>
                    <li><code style="background:#2c2c2c;padding:1px 5px;border-radius:3px;color:#03dac6;">?file=content/myexam.sef</code><br>
                        <span style="font-size:.85em;color:#888;">Load a file from the server by path.</span></li>
                    <li style="margin-top:8px;"><code style="background:#2c2c2c;padding:1px 5px;border-radius:3px;color:#03dac6;">?exam=&lt;base64&gt;</code><br>
                        <span style="font-size:.85em;color:#888;">Load an exam from a base64-encoded string.</span></li>
                </ul>
            </div>

            <!-- Full example -->
            <div class="help-section" style="grid-column: 1 / -1;">
                <h3>Complete Example File</h3>
                <div class="sef-example"><span class="sef-comment"># Questions are separated by a blank line
# Lines starting with # are treated as question text (no special comment syntax in SEF)</span>

<span class="sef-q">What does HTTP stand for?</span>
<span class="sef-ok">-* HyperText Transfer Protocol</span>
<span class="sef-no">-  HyperText Transmission Protocol</span>
<span class="sef-no">-  High Transfer Text Protocol</span>
<span class="sef-no">-  Hyper Transfer Text Process</span>

<span class="sef-q">Which of the following are valid HTTP methods?</span>
<span class="sef-ok">-* GET</span>
<span class="sef-ok">-* POST</span>
<span class="sef-ok">-* DELETE</span>
<span class="sef-no">-  FETCH</span>
<span class="sef-no">-  TRANSMIT</span>

<span class="sef-q">What is the default HTTP port?</span>
<span class="sef-ok">-* 80</span>
<span class="sef-no">-  443</span>
<span class="sef-no">-  8080</span>
<span class="sef-no">-  21</span></div>
            </div>

        </div><!-- /.help-grid -->

        <!-- GitHub reference box -->
        <div class="ref-box">
            <div class="ref-box-text">
                <h3>Smart Exam Format — Full Specification</h3>
                <p>The complete format specification, changelog, and additional examples are maintained in the official repository on GitHub.</p>
            </div>
            <a href="https://github.com/yllemo/Smart-Exam-Format" target="_blank" rel="noopener" class="btn btn-primary">
                View on GitHub &rarr;
            </a>
        </div>

    </div><!-- /.card -->

<?php elseif ($view === 'prompt'): ?>
<!-- ── PROMPT HELPER ─────────────────────────────────────── -->
    <div class="breadcrumb">
        <a href="index.php">Exam Files</a>
        <span>›</span> Prompt Helper
    </div>

    <div class="card">
        <h2 style="margin-top:0;">AI Prompt Helper</h2>
        <p style="color:#b0b0b0;font-size:.9em;margin-bottom:20px;">
            Fill in the fields below to generate a ready-to-use prompt for any AI assistant (Claude, ChatGPT, Gemini&hellip;).
            The prompt embeds the full SEF format rules so the AI outputs a correctly formatted
            <code style="background:#2c2c2c;padding:1px 5px;border-radius:3px;color:#03dac6;">.sef</code> file you can paste straight into the editor.
        </p>

        <div class="prompt-grid">

            <div class="form-group">
                <label>Topic / Subject <span style="color:#e74c3c;">*</span></label>
                <input class="form-control" type="text" id="ph-topic"
                       placeholder="e.g. Python list comprehensions, TCP/IP basics, World War II">
            </div>

            <div class="form-group">
                <label>Number of Questions</label>
                <input class="form-control" type="number" id="ph-count"
                       min="1" max="50" value="10">
            </div>

            <div class="form-group">
                <label>Difficulty</label>
                <select class="form-control" id="ph-difficulty">
                    <option value="mixed">Mixed (easy to hard)</option>
                    <option value="beginner">Beginner / Easy</option>
                    <option value="intermediate">Intermediate / Medium</option>
                    <option value="advanced">Advanced / Hard</option>
                </select>
            </div>

            <div class="form-group">
                <label>Answer Type</label>
                <select class="form-control" id="ph-answertype">
                    <option value="mixed">Mixed (single and multiple correct)</option>
                    <option value="single">Single correct answer only</option>
                    <option value="multiple">Multiple correct answers only</option>
                </select>
            </div>

            <div class="form-group">
                <label>Language</label>
                <input class="form-control" type="text" id="ph-language"
                       placeholder="English" value="English">
            </div>

            <div class="form-group">
                <label>Answers per Question</label>
                <select class="form-control" id="ph-options">
                    <option value="4">4 answer options</option>
                    <option value="3">3 answer options</option>
                    <option value="5">5 answer options</option>
                </select>
            </div>

            <div class="form-group full-width">
                <label>Extra Context <span style="color:#666;font-size:.85em;">(optional)</span></label>
                <textarea class="form-control" id="ph-context" rows="3"
                          placeholder="e.g. Focus on practical usage, avoid deprecated syntax. Target audience: junior developers."></textarea>
            </div>

        </div><!-- /.prompt-grid -->

        <div style="margin-top:20px;">
            <button class="btn btn-primary" onclick="buildPrompt()">Generate Prompt</button>
        </div>

        <!-- Output -->
        <div class="prompt-output-wrap" id="prompt-output-wrap">
            <h3>Generated Prompt — copy and paste into your AI assistant</h3>
            <textarea class="prompt-output-textarea" id="prompt-output" readonly spellcheck="false"></textarea>
            <div class="prompt-copy-row">
                <button class="btn btn-success" onclick="copyPrompt()">Copy to Clipboard</button>
                <span class="prompt-copied" id="prompt-copied">Copied!</span>
            </div>
        </div>

        <!-- Reference box -->
        <div class="ref-box" style="margin-top:28px;">
            <div class="ref-box-text">
                <h3>SEF Format — Full Specification &amp; AI Integration Guide</h3>
                <p>Extended format rules, two-state design, and AI workflow examples are documented in the official repository.</p>
            </div>
            <a href="https://github.com/yllemo/Smart-Exam-Format" target="_blank" rel="noopener" class="btn btn-primary">
                View on GitHub &rarr;
            </a>
        </div>

    </div><!-- /.card -->

<?php endif; ?>

</div><!-- /.main -->

<script>
// ── Question Builder ────────────────────────────────────────────────────────

function parseQuestion() {
    const ta = document.getElementById('question-input');
    const raw = ta.value;
    if (!raw.trim()) { alert('Enter a question first.'); return; }

    const lines = raw.split('\n');
    const parsed = lines
        .filter(l => l.trim() !== '')
        .map((line, i) => {
            line = line.trim();
            if (i === 0) return line;                          // question line — unchanged
            if (line.startsWith('-*') || line.startsWith('- ') || line === '-') return line; // already prefixed
            return '- ' + line;                                // add answer prefix
        });

    ta.value = parsed.join('\n');
}

function addQuestion() {
    const builder  = document.getElementById('question-input');
    const examArea = document.getElementById('exam-content');
    const content  = builder.value.trim();
    if (!content) { alert('Nothing to add — write and parse a question first.'); return; }

    const existing = examArea.value;
    const separator = (existing.trim() !== '' && !existing.endsWith('\n\n')) ? '\n\n' : '';
    examArea.value = existing + separator + content + '\n\n';
    builder.value = '';
    examArea.scrollTop = examArea.scrollHeight;
}

function clearBuilder() {
    if (document.getElementById('question-input').value.trim() === '') return;
    if (confirm('Clear the question builder?')) {
        document.getElementById('question-input').value = '';
    }
}

function clearExam() {
    if (document.getElementById('exam-content').value.trim() === '') return;
    if (confirm('Clear all exam content? This cannot be undone until you save.')) {
        document.getElementById('exam-content').value = '';
    }
}

function copyExam() {
    const content = document.getElementById('exam-content').value;
    if (!content.trim()) { alert('Nothing to copy.'); return; }
    navigator.clipboard.writeText(content)
        .then(() => alert('Copied to clipboard.'))
        .catch(err => alert('Copy failed: ' + err));
}

// ── Prompt Helper ───────────────────────────────────────────────────────────

const SEF_RULES = `\
## SMART EXAM FORMAT (SEF) — RULES

SEF is a plain-text format for multiple-choice exam questions.

### Structure
- One question per block. The FIRST line of a block is the question text.
- Every answer option line starts with a hyphen prefix:
    -* answer text   ← CORRECT answer
    -  answer text   ← WRONG answer (the space after - is optional)
- Blocks are separated by exactly ONE blank line.
- No numbering, no bullets, no headers, no markdown in the structure itself.

### Single-correct example (radio buttons in the simulator)
What is the capital of France?
-* Paris
- Berlin
- Madrid
- Rome

### Multiple-correct example (checkboxes in the simulator)
Which of these are primary colors?
-* Red
-* Blue
-* Yellow
- Green
- Purple

### Rules summary
1. First line of a block = question text (never starts with -)
2. Lines starting with -* = correct answer(s)
3. Lines starting with - (without *) = wrong answers
4. One blank line separates questions — no more, no less
5. Plain UTF-8 text, no special encoding required
6. Images/links can be embedded as [label](url) inside question or answer text`;

function buildPrompt() {
    const topic      = document.getElementById('ph-topic').value.trim();
    const count      = parseInt(document.getElementById('ph-count').value, 10) || 10;
    const difficulty = document.getElementById('ph-difficulty');
    const answertype = document.getElementById('ph-answertype');
    const language   = document.getElementById('ph-language').value.trim() || 'English';
    const options    = document.getElementById('ph-options').value;
    const context    = document.getElementById('ph-context').value.trim();

    if (!topic) {
        document.getElementById('ph-topic').focus();
        document.getElementById('ph-topic').style.borderColor = '#e74c3c';
        return;
    }
    document.getElementById('ph-topic').style.borderColor = '';

    const diffLabel = difficulty.options[difficulty.selectedIndex].text;
    const typeLabel = answertype.options[answertype.selectedIndex].text;

    const answerInstruction = {
        'mixed':    `Use a mix of single-correct and multiple-correct questions. For single-correct: exactly one -* line. For multiple-correct: two or more -* lines.`,
        'single':   `Every question must have exactly ONE correct answer (one -* line). All other answers use -.`,
        'multiple': `Every question must have TWO OR MORE correct answers (-* lines). Remaining answers use -.`
    }[answertype.value];

    let prompt = `You are an expert exam question writer. Generate a multiple-choice exam in Smart Exam Format (SEF).

${SEF_RULES}

## YOUR TASK

Topic:              ${topic}
Number of questions: ${count}
Options per question: ${options}
Difficulty:         ${diffLabel}
Answer type:        ${typeLabel}
Language:           ${language}`;

    if (context) {
        prompt += `\nAdditional instructions: ${context}`;
    }

    prompt += `

## ANSWER TYPE INSTRUCTION

${answerInstruction}

## OUTPUT RULES (STRICTLY FOLLOW)

- Output ONLY the raw .sef content. Nothing else.
- Do NOT include any introduction, explanation, or closing remarks.
- Do NOT wrap output in code fences (\`\`\`) or markdown blocks.
- Do NOT number the questions.
- Do NOT add section headers or labels.
- Start immediately with the first question text.
- Separate every question block with exactly one blank line.
- End the file after the last answer line with no trailing blank line.`;

    document.getElementById('prompt-output').value = prompt;
    const wrap = document.getElementById('prompt-output-wrap');
    wrap.classList.add('visible');
    wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function copyPrompt() {
    const ta = document.getElementById('prompt-output');
    if (!ta.value.trim()) return;
    navigator.clipboard.writeText(ta.value).then(() => {
        const badge = document.getElementById('prompt-copied');
        badge.classList.add('show');
        setTimeout(() => badge.classList.remove('show'), 2000);
    }).catch(err => alert('Copy failed: ' + err));
}

// ── Auto-sanitize filename input: strip special chars on blur
const fnInput = document.getElementById('filename-input');
if (fnInput) {
    fnInput.addEventListener('blur', () => {
        fnInput.value = fnInput.value.replace(/[^a-zA-Z0-9_\-]/g, '_').replace(/^_+|_+$/g, '');
    });
}

// Warn on unsaved changes when navigating away from editor
(function() {
    const examArea = document.getElementById('exam-content');
    if (!examArea) return;
    const original = examArea.value;
    window.addEventListener('beforeunload', e => {
        if (examArea.value !== original) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    document.getElementById('save-form').addEventListener('submit', () => {
        window.removeEventListener('beforeunload', () => {});
    });
})();
</script>

<?php endif; ?>

</body>
</html>
