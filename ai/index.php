<?php
session_start();

// ── Bootstrap ──────────────────────────────────────────────────────────────
$rootDir   = dirname(__DIR__);
$config    = json_decode(@file_get_contents($rootDir . '/config/config.json'), true) ?? [];
$aiCfgPath = $rootDir . '/config/ai.json';
$aiConfig  = json_decode(@file_get_contents($aiCfgPath) ?: '{}', true) ?? [];

$apiUrl    = $aiConfig['api_url'] ?? 'https://api.openai.com/v1';
$apiKey    = $aiConfig['api_key'] ?? '';
$apiModel  = $aiConfig['model']   ?? 'gpt-4o';
$siteTitle = htmlspecialchars($config['title'] ?? 'Smart Exam');

// ── Auth — shared session with /admin ─────────────────────────────────────
$adminCfg     = json_decode(@file_get_contents($rootDir . '/config/admin.json') ?: '{}', true) ?? [];
$passwordHash = $adminCfg['password_hash'] ?? '';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken       = $_SESSION['csrf_token'];
$isAuthenticated = !empty($_SESSION['admin_authenticated']);

// Logout via GET
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_authenticated']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$generatedSef     = '';
$inputText        = '';
$additionalWishes = '';
$numQuestionsPost = 10;
$errorMsg         = '';
$infoMsg          = '';
$savedFile        = '';

// ── Request handling ───────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read fields from POST; api_key falls back to the config file key if left blank
    $postApiUrl       = trim($_POST['api_url']           ?? $apiUrl);
    $postApiKey       = trim($_POST['api_key']           ?? '') ?: $apiKey;
    $postModel        = trim($_POST['model']             ?? $apiModel);
    $inputText        = $_POST['input_text']             ?? '';
    $additionalWishes = trim($_POST['additional_wishes'] ?? '');
    $numQuestionsPost = max(1, min(50, (int)($_POST['num_questions'] ?? 10)));
    // Carry generated content across non-generate actions
    $generatedSef = $_POST['sef_content'] ?? '';

    // ── Login ──────────────────────────────────────────────────────────────
    if ($action === 'login') {
        if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
            $errorMsg = 'Invalid request token.';
        } elseif (empty($passwordHash)) {
            $errorMsg = 'No admin password configured. Set one up in /admin first.';
        } elseif (password_verify($_POST['password'] ?? '', $passwordHash)) {
            $_SESSION['admin_authenticated'] = true;
            $isAuthenticated = true;
            $infoMsg = 'Logged in.';
        } else {
            $errorMsg = 'Incorrect password.';
        }
    }

    // ── Generate questions via AI ──────────────────────────────────────────
    if ($action === 'generate') {
        $numQ      = $numQuestionsPost;
        $sysPrompt = trim($_POST['system_prompt'] ?? '');
        $inputText = trim($inputText);

        if (!$postApiKey) {
            $errorMsg = 'API key is required. Add it to config/ai.json or enter it in the settings panel.';
        } elseif (!$inputText) {
            $errorMsg = 'Paste exam results in the left panel first.';
        } elseif (!function_exists('curl_init')) {
            $errorMsg = 'PHP cURL extension is not available on this server.';
        } else {
            $userMsg  = "Analyze the following exam results and generate {$numQ} new SEF questions.\n"
                      . "Focus on the topics where the user gave wrong answers.\n"
                      . "Keep the same language as the original questions.\n"
                      . ($additionalWishes ? "Additional requirements: {$additionalWishes}\n" : "")
                      . "\n"
                      . $inputText;

            $endpoint = rtrim($postApiUrl, '/') . '/chat/completions';
            $payload  = json_encode([
                'model'       => $postModel,
                'messages'    => [
                    ['role' => 'system', 'content' => $sysPrompt],
                    ['role' => 'user',   'content' => $userMsg],
                ],
                'temperature' => 0.7,
            ]);

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $postApiKey,
                ],
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp    = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false) {
                $errorMsg = 'Connection failed: ' . $curlErr;
            } else {
                $data = json_decode($resp, true);
                if ($httpCode !== 200) {
                    $errorMsg = "API error ({$httpCode}): "
                              . ($data['error']['message'] ?? substr($resp, 0, 300));
                } else {
                    $raw = $data['choices'][0]['message']['content'] ?? '';
                    // Strip wrapping code fences that some models add
                    $raw = preg_replace('/^```(?:sef|text|markdown)?\r?\n/m', '', trim($raw));
                    $raw = preg_replace('/\n```\s*$/', '', $raw);
                    $generatedSef = trim($raw);
                    $infoMsg = "Generated {$numQ} questions successfully.";
                }
            }
        }
    }

    // ── Save generated .sef to /content (requires auth) ───────────────────
    if ($action === 'save_file' && !$isAuthenticated) {
        $errorMsg = 'You must be logged in to save files.';
    }
    if ($action === 'save_file' && $isAuthenticated) {
        $sefContent = $_POST['sef_content'] ?? '';
        $rawName    = trim($_POST['filename'] ?? 'ai_generated');
        $filename   = preg_replace('/[^a-zA-Z0-9_-]/', '_', $rawName);
        if (!str_ends_with($filename, '.sef')) $filename .= '.sef';
        $path = $rootDir . '/content/' . $filename;
        if (file_put_contents($path, $sefContent) !== false) {
            $savedFile = $filename;
            $infoMsg   = "Saved to content/{$filename}";
        } else {
            $errorMsg = "Could not write content/{$filename} — check directory permissions.";
        }
    }
}

// ── Load input from URL (?input=base64) ────────────────────────────────────
if (empty($inputText) && !empty($_GET['input'])) {
    $decoded = base64_decode($_GET['input']);
    if ($decoded !== false) $inputText = $decoded;
}

// ── Default system prompt ──────────────────────────────────────────────────
$defaultPrompt = <<<'EOT'
You are an exam question generator for the Smart Exam Framework (SEF) format.

SEF INPUT FORMAT:
- Questions and answer blocks separated by blank lines
- `-`  = wrong answer
- `-*` = correct answer
- Multiple `-*` lines → multiple-choice question (checkboxes)
- `#` lines are comments, ignored by the parser
- `---` lines are section separators, ignored by the parser
- Markdown supported in question text: **bold**, *italic*, `code`, [link](url), ![image](url)
- Code blocks (```lang … ```) inside a question are rendered as part of the question
- Mermaid diagrams: ```mermaid … ``` are rendered as interactive diagrams

OPTIONAL YAML FRONTMATTER (at top of file):
---
name: Exam Title
description: Brief description
---

RESULT FORMAT (your input — for analysis):
Each answer line is prefixed with the user's selection:
  [ ] = user did NOT select   |   [*] = user DID select
Combined with the correctness marker:
  [ ] -*  = user missed a correct answer          ✗  (knowledge gap)
  [*] -*  = user correctly selected a right answer ✓
  [*] -   = user wrongly selected an incorrect answer ✗  (misconception)
  [ ] -   = user correctly avoided a wrong answer  ✓

EXAMPLE OUTPUT:
---
name: AI Follow-up – Targeted Review
description: Focused on areas needing improvement
---

# ── Core Concepts ────────────────────────────────────────────────────────────

What does **REST** stand for in web APIs?
- Remote Execution State Transfer
-* Representational State Transfer
- Request Execution Service Technology
- Rapid Endpoint Service Template

Which HTTP status codes indicate a **client** error? (select all that apply)
-* 400 Bad Request
-* 404 Not Found
- 200 OK
-* 403 Forbidden
- 500 Internal Server Error

```mermaid
flowchart LR
    Client -->|Request| Server
    Server -->|200| A[Success]
    Server -->|4xx| B[Client Error]
    Server -->|5xx| C[Server Error]
```
What does HTTP 4xx represent in this flow?
- A server-side failure
-* An error caused by the client request
- A successful redirect
- A connection timeout

OUTPUT RULES:
- Output ONLY valid SEF — no explanations, no wrapping code fences
- Start with YAML frontmatter
- Focus on topics where the user gave wrong answers
- Keep the same language as the original questions
- Mix single-answer and multiple-answer question types
- Use mermaid diagrams where they clarify technical or structural concepts
EOT;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Generator – <?= $siteTitle ?></title>
    <link rel="stylesheet" href="../style.css">
    <style>
        /* ── Override exam-page body constraints ───────────────────────── */
        *, *::before, *::after { box-sizing: border-box; }
        body {
            height: auto;
            min-height: 100vh;
            overflow: auto;
            display: block;
            font-family: Arial, sans-serif;
        }

        /* ── Page layout ────────────────────────────────────────────────── */
        .ai-header {
            background-color: #1e1e1e;
            border-bottom: 1px solid #333;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .ai-header a {
            color: #03dac6;
            text-decoration: none;
            font-size: .9em;
            white-space: nowrap;
        }
        .ai-header a:hover { text-decoration: underline; }
        .ai-header h1 {
            margin: 0;
            font-size: 1.05em;
            font-weight: 600;
            color: #e0e0e0;
        }

        .ai-main {
            max-width: 1280px;
            margin: 0 auto;
            padding: 20px 20px 40px;
        }

        /* ── Alerts ─────────────────────────────────────────────────────── */
        .alert {
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: .9em;
        }
        .alert-error { background: #2a1111; border: 1px solid #6b2020; color: #f77; }
        .alert-info  { background: #0f2a18; border: 1px solid #1d6b35; color: #4caf50; }

        /* ── Config panel ───────────────────────────────────────────────── */
        .config-panel {
            background-color: #1e1e1e;
            border: 1px solid #333;
            border-radius: 10px;
            margin-bottom: 18px;
            overflow: hidden;
        }
        .config-toggle {
            width: 100%;
            background: none;
            border: none;
            color: #b0b0b0;
            padding: 10px 16px;
            text-align: left;
            cursor: pointer;
            font-size: .9em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .config-toggle:hover { background-color: #252525; color: #e0e0e0; }
        .config-status { margin-left: auto; font-size: .8em; }
        .status-ok  { color: #4caf50; }
        .status-err { color: #f44336; }
        .config-body {
            padding: 16px 16px 12px;
            border-top: 1px solid #333;
            display: none;
        }
        .config-body.open { display: block; }
        .config-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .config-field { flex: 1; min-width: 180px; }
        .config-field label {
            display: block;
            font-size: .78em;
            color: #03dac6;
            margin-bottom: 4px;
        }
        .config-field input {
            width: 100%;
            background-color: #121212;
            border: 1px solid #444;
            border-radius: 5px;
            color: #e0e0e0;
            padding: 7px 10px;
            font-size: .9em;
            font-family: inherit;
        }
        .config-field input:focus { outline: none; border-color: #6200ee; }
        .key-wrap { position: relative; }
        .key-wrap input { padding-right: 2.2rem; }
        .key-eye {
            position: absolute; right: 8px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: #555; cursor: pointer; font-size: .85em; padding: 0;
        }
        .key-eye:hover { color: #aaa; }

        /* ── Two-column layout ──────────────────────────────────────────── */
        .ai-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 14px;
        }
        @media (max-width: 768px) { .ai-columns { grid-template-columns: 1fr; } }

        .panel {
            background-color: #1e1e1e;
            border: 1px solid #333;
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 8px rgba(0,0,0,.2);
        }
        .panel-header {
            padding: 8px 14px;
            background-color: #252525;
            font-size: .82em;
            color: #03dac6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-shrink: 0;
            border-bottom: 1px solid #333;
        }
        .panel-header-actions { display: flex; gap: 6px; }
        .panel-body { padding: 12px; flex: 1; display: flex; flex-direction: column; }

        textarea.code-area {
            flex: 1;
            min-height: 340px;
            width: 100%;
            background-color: #121212;
            border: 1px solid #333;
            border-radius: 5px;
            color: #e0e0e0;
            font-family: monospace;
            font-size: .82em;
            line-height: 1.55;
            padding: 10px;
            resize: vertical;
        }
        textarea.code-area:focus { outline: none; border-color: #6200ee; }
        textarea.code-area::placeholder { color: #444; }

        .char-count {
            font-size: .72em;
            color: #555;
            text-align: right;
            margin-top: 4px;
        }

        /* ── Save row ───────────────────────────────────────────────────── */
        .save-row {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .save-row input[type=text] {
            flex: 1;
            min-width: 130px;
            background-color: #121212;
            border: 1px solid #444;
            border-radius: 5px;
            color: #e0e0e0;
            padding: 6px 10px;
            font-size: .85em;
            font-family: inherit;
        }
        .save-row input::placeholder { color: #444; }
        .saved-link {
            margin-top: 8px;
            font-size: .82em;
            color: #4caf50;
        }
        .saved-link a { color: #03dac6; }

        /* ── Options row ────────────────────────────────────────────────── */
        .options-row {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .options-row label {
            font-size: .88em;
            color: #b0b0b0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .options-row input[type=number] {
            width: 68px;
            background-color: #121212;
            border: 1px solid #444;
            border-radius: 5px;
            color: #e0e0e0;
            padding: 5px 8px;
            font-size: .9em;
            font-family: inherit;
        }

        /* ── System prompt ──────────────────────────────────────────────── */
        .prompt-toggle {
            background: none;
            border: none;
            color: #555;
            font-size: .82em;
            cursor: pointer;
            padding: 0;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 8px;
        }
        .prompt-toggle:hover { color: #03dac6; }
        textarea.prompt-area {
            width: 100%;
            background-color: #121212;
            border: 1px solid #333;
            border-radius: 5px;
            color: #888;
            font-family: monospace;
            font-size: .77em;
            line-height: 1.5;
            padding: 10px;
            resize: vertical;
            min-height: 220px;
            margin-bottom: 14px;
        }
        textarea.prompt-area:focus { outline: none; border-color: #6200ee; }

        /* ── Button variants ────────────────────────────────────────────── */
        /* base button style comes from style.css (#6200ee purple) */
        .btn-sm {
            padding: 6px 14px;
            font-size: .82em;
        }
        .btn-ghost {
            background-color: #2e2e2e;
            color: #b0b0b0;
            border: 1px solid #444;
        }
        .btn-ghost:hover { background-color: #3a3a3a; color: #e0e0e0; }
        .btn-ghost:disabled { background-color: #555; opacity: .5; cursor: not-allowed; }

        .btn-teal {
            background-color: #00695c;
            color: #e0f2f1;
        }
        .btn-teal:hover { background-color: #00796b; }
        .btn-teal:disabled { background-color: #555; opacity: .5; cursor: not-allowed; }

        .btn-generate {
            background-color: #6200ee;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 11px 28px;
            font-size: 1em;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 3px 12px rgba(98,0,238,.4);
            transition: background-color .2s;
        }
        .btn-generate:hover { background-color: #3700b3; }
        .btn-generate:disabled { background-color: #555; box-shadow: none; cursor: not-allowed; }

        /* Spinner */
        .spinner {
            display: none;
            width: 15px; height: 15px;
            border: 2px solid rgba(255,255,255,.25);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .55s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .generate-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .gen-hint { font-size: .82em; color: #555; }

        /* ── Auth bar ───────────────────────────────────────────────────── */
        .auth-bar {
            background-color: #1e1e1e;
            border-bottom: 1px solid #333;
            padding: 8px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: .85em;
        }
        .auth-bar-msg { color: #888; }
        .auth-bar form { display: flex; gap: 8px; align-items: center; margin: 0; }
        .auth-bar input[type=password] {
            background: #121212;
            border: 1px solid #444;
            border-radius: 5px;
            color: #e0e0e0;
            padding: 5px 10px;
            font-size: .85em;
            font-family: inherit;
            width: 180px;
        }
        .auth-bar input[type=password]:focus { outline: none; border-color: #6200ee; }
        .auth-bar button[type=submit] {
            padding: 5px 14px;
            font-size: .85em;
        }
        .auth-bar-ok { color: #4caf50; justify-content: flex-end; }
        .auth-bar-ok a {
            color: #888;
            text-decoration: none;
            font-size: .85em;
            margin-left: 8px;
        }
        .auth-bar-ok a:hover { color: #e0e0e0; text-decoration: underline; }

        /* ── Loading overlay ────────────────────────────────────────────── */
        #loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.75);
            z-index: 200;
            justify-content: center;
            align-items: center;
        }
        #loading-overlay.active { display: flex; }
        .loading-box {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 12px;
            padding: 36px 48px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
        }
        .loading-spinner {
            width: 44px; height: 44px;
            border: 4px solid #333;
            border-top-color: #6200ee;
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        .loading-title { font-size: 1.1em; color: #e0e0e0; font-weight: 600; }
        .loading-sub   { font-size: .85em; color: #666; }
    </style>
</head>
<body>

<div class="ai-header">
    <a href="../">← Back to Exams</a>
    <h1>✨ AI Question Generator</h1>
</div>

<?php if ($isAuthenticated): ?>
<div class="auth-bar auth-bar-ok">
    <span>🔓 Logged in as admin</span>
    <a href="?logout=1">Log out</a>
</div>
<?php else: ?>
<div class="auth-bar">
    <span class="auth-bar-msg">🔒 Log in to save generated exams to /content</span>
    <form method="POST">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="sef_content"        value="<?= htmlspecialchars($generatedSef) ?>">
        <input type="hidden" name="input_text"         value="<?= htmlspecialchars($inputText) ?>">
        <input type="hidden" name="additional_wishes"  value="<?= htmlspecialchars($additionalWishes) ?>">
        <input type="hidden" name="num_questions"      value="<?= $numQuestionsPost ?>">
        <input type="password" name="password" placeholder="Admin password"
               autocomplete="current-password" required>
        <button type="submit">Log in</button>
    </form>
</div>
<?php endif; ?>

<div class="ai-main">

    <?php if ($errorMsg): ?>
    <div class="alert alert-error">⚠ <?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>
    <?php if ($infoMsg): ?>
    <div class="alert alert-info">✓ <?= htmlspecialchars($infoMsg) ?></div>
    <?php endif; ?>

    <!-- ── Single form — action determined by submit button value ── -->
    <!-- Loading overlay — shown while the API request is in flight -->
    <div id="loading-overlay">
        <div class="loading-box">
            <div class="loading-spinner"></div>
            <div class="loading-title">Generating questions…</div>
            <div class="loading-sub">Talking to AI, this may take up to a minute</div>
        </div>
    </div>

    <form method="POST" id="ai-form">
        <input type="hidden" name="action" id="form-action" value="">

        <!-- ── API Config panel ─────────────────────────────────────────── -->
        <div class="config-panel">
            <button type="button" class="config-toggle" onclick="toggleConfig()">
                ⚙ API Configuration
                <span class="config-status <?= $apiKey ? 'status-ok' : 'status-err' ?>">
                    <?= $apiKey ? '● Connected' : '● Not configured' ?>
                </span>
            </button>
            <div class="config-body <?= (!$apiKey || $action === 'save_config') ? 'open' : '' ?>" id="config-body">
                <div class="config-row">
                    <div class="config-field">
                        <label>API Base URL</label>
                        <input type="text" name="api_url" value="<?= htmlspecialchars($apiUrl) ?>"
                               placeholder="https://api.openai.com/v1">
                    </div>
                    <div class="config-field" style="flex:.6;min-width:140px">
                        <label>Model</label>
                        <input type="text" name="model" value="<?= htmlspecialchars($apiModel) ?>"
                               placeholder="gpt-4o">
                    </div>
                    <div class="config-field">
                        <label>API Key <span style="color:#555">(leave blank to use key from config/ai.json)</span></label>
                        <div class="key-wrap">
                            <input type="password" name="api_key" id="api-key-inp"
                                   value="" placeholder="<?= $apiKey ? '●●●●●●●● (configured)' : 'sk-…' ?>">
                            <button type="button" class="key-eye" onclick="toggleKeyVis()">👁</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Two-column main area ─────────────────────────────────────── -->
        <div class="ai-columns">

            <!-- Left: exam results input -->
            <div class="panel">
                <div class="panel-header">
                    <span>📋 Exam Results</span>
                    <span style="color:#3a4a6a">paste or load via ?input=</span>
                </div>
                <div class="panel-body">
                    <textarea class="code-area" name="input_text" id="input-text"
                              placeholder="Paste exam results here, or open this page with ?input=<base64> from the exam viewer…"
                              oninput="updateCount()"><?= htmlspecialchars($inputText) ?></textarea>
                    <div class="char-count" id="char-count"><?= strlen($inputText) ?> chars</div>
                </div>
            </div>

            <!-- Right: generated SEF -->
            <div class="panel">
                <div class="panel-header">
                    <span>📝 Generated SEF</span>
                    <div class="panel-header-actions">
                        <button type="button" class="btn-sm btn-ghost" id="btn-copy"
                                onclick="copySef()" <?= $generatedSef ? '' : 'disabled' ?>>Copy</button>
                        <button type="button" class="btn-sm btn-teal" id="btn-open"
                                onclick="openAsExam()" <?= $generatedSef ? '' : 'disabled' ?>>Open as Exam →</button>
                    </div>
                </div>
                <div class="panel-body">
                    <textarea class="code-area" name="sef_content" id="sef-output"
                              placeholder="Generated questions appear here after clicking Generate…"><?= htmlspecialchars($generatedSef) ?></textarea>
                    <div class="save-row">
                        <input type="text" name="filename" placeholder="filename (without .sef)"
                               value="<?= $savedFile ? htmlspecialchars(pathinfo($savedFile, PATHINFO_FILENAME)) : 'ai_generated' ?>"
                               id="filename-inp">
                        <button type="submit" class="btn-sm" id="btn-save"
                                <?= ($generatedSef && $isAuthenticated) ? '' : 'disabled' ?>
                                title="<?= !$isAuthenticated ? 'Log in (see top bar) to save files' : '' ?>"
                                onclick="return submitSaveFile()">💾 Save to /content</button>
                    </div>
                    <?php if ($savedFile): ?>
                    <div class="saved-link">
                        ✓ Saved!
                        <a href="../?file=<?= htmlspecialchars(basename($savedFile)) ?>">Open exam →</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Options ──────────────────────────────────────────────────── -->
        <div class="options-row">
            <label>
                Questions to generate:
                <input type="number" name="num_questions"
                       value="<?= $numQuestionsPost ?>" min="1" max="50">
            </label>
        </div>

        <!-- ── Additional wishes ────────────────────────────────────────── -->
        <div style="margin-bottom:14px">
            <label style="display:block;font-size:.82em;color:#03dac6;margin-bottom:5px;">
                Additional requirements <span style="color:#555">(optional)</span>
            </label>
            <textarea name="additional_wishes" id="additional-wishes"
                      rows="2"
                      placeholder="e.g. Use simpler language · Focus on practical examples · Add more multiple-choice questions · Generate in Swedish"
                      style="width:100%;background:#121212;border:1px solid #333;border-radius:5px;
                             color:#e0e0e0;font-family:inherit;font-size:.88em;padding:8px 10px;
                             resize:vertical;line-height:1.5;"><?= htmlspecialchars($additionalWishes) ?></textarea>
        </div>

        <!-- ── System prompt (expandable) ───────────────────────────────── -->
        <button type="button" class="prompt-toggle" id="prompt-btn" onclick="togglePrompt()">
            <span id="prompt-arrow">▶</span> System prompt <span style="color:#444">(click to view/edit)</span>
        </button>
        <div id="prompt-body" style="display:none">
            <textarea class="prompt-area" name="system_prompt"><?= htmlspecialchars($defaultPrompt) ?></textarea>
        </div>

        <!-- ── Generate + Open as Exam ──────────────────────────────────── -->
        <div class="generate-row">
            <button type="submit" class="btn-generate" id="btn-generate"
                    onclick="return startGenerate()">
                ✨ Generate Questions
            </button>
            <?php if ($generatedSef): ?>
            <button type="button" class="btn-generate" id="btn-open-main"
                    onclick="openAsExam()"
                    style="background-color:#00695c;box-shadow:0 3px 12px rgba(0,105,92,.4)">
                ▶ Open as Exam
            </button>
            <?php endif; ?>
            <span class="gen-hint" id="gen-hint">
                <?php if ($generatedSef): ?>Generated — edit if needed, then open or save.<?php endif; ?>
            </span>
        </div>

    </form>
</div>

<script>
// ── Config panel ──────────────────────────────────────────────────────────
function toggleConfig() {
    document.getElementById('config-body').classList.toggle('open');
}
function toggleKeyVis() {
    const inp = document.getElementById('api-key-inp');
    inp.type = inp.type === 'password' ? 'text' : 'password';
}

// ── System prompt ─────────────────────────────────────────────────────────
function togglePrompt() {
    const body  = document.getElementById('prompt-body');
    const arrow = document.getElementById('prompt-arrow');
    const open  = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    arrow.textContent  = open ? '▶' : '▼';
}

// ── Input char counter ────────────────────────────────────────────────────
function updateCount() {
    document.getElementById('char-count').textContent =
        document.getElementById('input-text').value.length + ' chars';
}

// ── UTF-8 safe base64 encode ──────────────────────────────────────────────
function textToBase64(str) {
    return btoa(encodeURIComponent(str).replace(/%([0-9A-F]{2})/gi,
        (_, p1) => String.fromCharCode(parseInt(p1, 16))));
}

// ── Copy generated SEF ────────────────────────────────────────────────────
function copySef() {
    const text = document.getElementById('sef-output').value;
    if (!text.trim()) return;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('btn-copy');
        const orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = orig, 1800);
    });
}

// ── Open generated SEF as exam ────────────────────────────────────────────
function openAsExam() {
    const text = document.getElementById('sef-output').value.trim();
    if (!text) { alert('Nothing to open — generate questions first.'); return; }
    window.open('../?exam=' + encodeURIComponent(textToBase64(text)), '_blank');
}

// ── Enable output buttons when textarea has content ───────────────────────
const isAuthed = <?= $isAuthenticated ? 'true' : 'false' ?>;
document.getElementById('sef-output').addEventListener('input', function() {
    const has = this.value.trim().length > 0;
    document.getElementById('btn-copy').disabled = !has;
    document.getElementById('btn-open').disabled = !has;
    document.getElementById('btn-save').disabled = !(has && isAuthed);
});

// ── Form action helpers ───────────────────────────────────────────────────
function startGenerate() {
    const input = document.getElementById('input-text').value.trim();
    if (!input) { alert('Paste exam results in the left panel first.'); return false; }
    document.getElementById('form-action').value = 'generate';
    document.getElementById('loading-overlay').classList.add('active');
    return true;
}

function submitSaveFile() {
    const fn = document.getElementById('filename-inp').value.trim();
    if (!fn) { alert('Please enter a filename.'); return false; }
    if (!document.getElementById('sef-output').value.trim()) { alert('Nothing to save.'); return false; }
    document.getElementById('form-action').value = 'save_file';
    return true;
}
</script>
</body>
</html>
