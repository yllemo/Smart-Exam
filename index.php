<?php
$config = json_decode(file_get_contents(__DIR__ . '/config/config.json'), true);
$title       = htmlspecialchars($config['title']       ?? 'Smart Exam');
$favicon     = htmlspecialchars($config['favicon']     ?? '');
$stylesheet  = htmlspecialchars($config['stylesheet']  ?? 'style.css');
$description = htmlspecialchars($config['description'] ?? '');

// ── SEF helpers ────────────────────────────────────────────────────────────

// Parse YAML frontmatter from the first block of a .sef file.
// Returns an associative array of key → value pairs, or [] if absent.
function parseSefMeta(string $path): array {
    $chunk = @file_get_contents($path, false, null, 0, 1024);
    if (!$chunk || !preg_match('/^---\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|$)/s', $chunk, $m)) return [];
    $meta = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (preg_match('/^(\w[\w\s]*?)\s*:\s*(.+)$/', trim($line), $kv)) {
            $meta[trim($kv[1])] = trim($kv[2]);
        }
    }
    return $meta;
}

// Count answerable question blocks in a .sef file (blocks that have at least
// one answer line starting with "-"). Comments, separators, and code fences
// are handled correctly.
function countSefQuestions(string $path): int {
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!$lines) return 0;

    // Skip frontmatter
    $i = 0;
    if (isset($lines[0]) && trim($lines[0]) === '---') {
        for ($i = 1; $i < count($lines); $i++) {
            if (preg_match('/^---\s*$/', trim($lines[$i]))) { $i++; break; }
        }
    }

    $count = 0; $hasAnswers = false; $inBlock = false; $inCode = false;
    for (; $i < count($lines); $i++) {
        $t = trim($lines[$i]);
        if ($inCode) { if (preg_match('/^```\s*$/', $t)) $inCode = false; continue; }
        if (str_starts_with($t, '```')) { $inCode = true; $inBlock = true; continue; }
        if ($t === '') {
            if ($inBlock && $hasAnswers) $count++;
            $inBlock = $hasAnswers = false;
            continue;
        }
        if ($t[0] === '#' || str_starts_with($t, '---')) continue;
        if ($t[0] === '-') { $hasAnswers = true; $inBlock = true; }
        else $inBlock = true;
    }
    if ($inBlock && $hasAnswers) $count++;
    return $count;
}

// ── Scan content directory ─────────────────────────────────────────────────

$sefFiles = [];
$contentDir = __DIR__ . '/content';
if (is_dir($contentDir)) {
    foreach (scandir($contentDir) as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'sef') continue;
        $path = $contentDir . '/' . $file;
        $meta = parseSefMeta($path);
        $sefFiles[] = [
            'file'      => $file,
            'basename'  => pathinfo($file, PATHINFO_FILENAME),
            'name'      => $meta['name']        ?? pathinfo($file, PATHINFO_FILENAME),
            'desc'      => $meta['description'] ?? '',
            'questions' => countSefQuestions($path),
        ];
    }
    usort($sefFiles, fn($a, $b) => strcmp($a['file'], $b['file']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($description): ?>
    <meta name="description" content="<?= $description ?>">
    <?php endif; ?>
    <title><?= $title ?></title>
    <?php if ($favicon): ?>
    <link rel="icon" type="image/svg+xml" href="<?= $favicon ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $stylesheet ?>">
</head>
<body>
    <div id="progress-info" class="progress-info">
        <span id="progress-text"></span>
        <div class="zoom-controls">
            <button id="zoom-out" onclick="zoomOut()" title="Zoom out">−</button>
            <span id="zoom-label">100%</span>
            <button id="zoom-in" onclick="zoomIn()" title="Zoom in">+</button>
        </div>
    </div>
    <div class="container-wrapper">
        <div class="container">

            <div id="question-block" class="question-block"></div>
            <div id="answer-block" class="answer-block"></div>

            <div id="result-screen" class="result-screen">
                <h2>Exam Results</h2>
                <p id="score"></p>
                <ul id="result-list" class="result-list"></ul>
                <button id="reset-button">Restart Exam</button>
                <button id="back-button">Back to Exams</button>
                <button id="redo-incorrect-button" style="display: none;">Redo Incorrect Answers</button>
                <button id="ai-generate-button" style="display:none;background:linear-gradient(135deg,#0d9488,#0f766e);box-shadow:0 2px 10px rgba(13,148,136,.35);" onclick="openAiGenerator()">✨ New Questions</button>
                <button id="toggle-result-button" style="display: inline-block;" onclick="toggleResults()">Results</button>
            </div>

            <div id="file-list-section" class="file-list-section">
                <h1 class="exam-hub-title"><?= $title ?></h1>
                <?php if (empty($sefFiles)): ?>
                <p class="no-files">No exam files found. Add <code>.sef</code> files to the <code>content/</code> directory.</p>
                <?php else: ?>
                <div class="exam-grid">
                    <?php foreach ($sefFiles as $f): ?>
                    <a class="exam-card"
                       href="?file=<?= htmlspecialchars($f['file']) ?>">
                        <div class="exam-card-name"><?= htmlspecialchars($f['name']) ?></div>
                        <?php if ($f['desc']): ?>
                        <div class="exam-card-desc"><?= htmlspecialchars($f['desc']) ?></div>
                        <?php endif; ?>
                        <div class="exam-card-footer">
                            <span class="exam-card-count">
                                <?= $f['questions'] ?> question<?= $f['questions'] !== 1 ? 's' : '' ?>
                            </span>
                            <span class="exam-card-start">Start &rarr;</span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <div class="button-container">
        <div class="checkbox-container">
            <input type="checkbox" id="showCorrectAnswers">
            <label for="showCorrectAnswers">
                <span class="label-full">Show Answer</span>
                <span class="label-short">Show</span>
            </label>
        </div>
        <button id="prev-button" disabled>Previous</button>
        <button id="next-button">Next</button>
        <button id="end-exam-button">End Exam</button>
    </div>

    <!-- Modal overlay for result output -->
    <div id="modal-overlay" class="modal-overlay">
        <div class="modal-content" id="hidden-output"></div>
        <div>
            <button class="modal-copy" onclick="copyToClipboard()">Copy to Clipboard</button>
            <button class="modal-close" onclick="toggleResults()">Close</button>
        </div>
    </div>

    <script src="js/marked.min.js"></script>
    <script src="js/mermaid.min.js"></script>
    <script src="app.js"></script>
</body>
</html>
