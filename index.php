<?php
$config = json_decode(file_get_contents(__DIR__ . '/config/config.json'), true);
$title      = htmlspecialchars($config['title']       ?? 'Smart Exam');
$favicon    = htmlspecialchars($config['favicon']     ?? '');
$stylesheet = htmlspecialchars($config['stylesheet']  ?? 'style.css');
$description = htmlspecialchars($config['description'] ?? '');

// Scan content directory for .sef files
$sefFiles = [];
$contentDir = __DIR__ . '/content';
if (is_dir($contentDir)) {
    foreach (scandir($contentDir) as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sef') {
            $sefFiles[] = $file;
        }
    }
    sort($sefFiles);
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
                <button id="toggle-result-button" style="display: inline-block;" onclick="toggleResults()">Results</button>
            </div>

            <div id="file-list-section" class="file-list-section">
                <h2><?= $title ?></h2>
                <?php if (empty($sefFiles)): ?>
                <p class="no-files">No exam files found. Add <code>.sef</code> files to the <code>content/</code> directory.</p>
                <?php else: ?>
                <ul class="file-list">
                    <?php foreach ($sefFiles as $file): ?>
                    <li>
                        <a href="#" onclick="loadExamFromUrl('content/<?= htmlspecialchars($file) ?>'); return false;">
                            <?= htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
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

    <script src="app.js"></script>
</body>
</html>
