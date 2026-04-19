// ── Mermaid ───────────────────────────────────────────────────────────────────
if (window.mermaid) {
    mermaid.initialize({ startOnLoad: false, theme: 'dark', securityLevel: 'loose' });
}

// ── Marked ────────────────────────────────────────────────────────────────────
if (window.marked) {
    // html: false — raw HTML in .sef content is escaped and shown as text
    // instead of being injected into the DOM. Prevents <title>, <h1>, etc.
    // in answers/questions from vanishing or being misinterpreted.
    marked.use({ gfm: true, breaks: true, html: false });
}

let questions = [];
let currentQuestionIndex = 0;
let userAnswers = [];

// ── Frontmatter ───────────────────────────────────────────────────────────────
// Strips an optional YAML frontmatter block (---\n...\n---) from the top of
// the text and returns { meta: {key:value, …}, body: <rest of text> }.
// Only simple "key: value" pairs are supported (no nested YAML).
function parseFrontmatter(text) {
    const match = text.match(/^---\r?\n([\s\S]*?)\r?\n---[ \t]*(?:\r?\n|$)([\s\S]*)$/);
    if (!match) return { meta: {}, body: text };

    const meta = {};
    for (const line of match[1].split('\n')) {
        const kv = line.match(/^(\w[\w\s]*?)\s*:\s*(.+)$/);
        if (kv) meta[kv[1].trim()] = kv[2].trim();
    }
    return { meta, body: match[2] };
}

// ── Zoom ──────────────────────────────────────────────────────────────────────
const ZOOM_MIN  = 70;
const ZOOM_MAX  = 150;
const ZOOM_STEP = 10;
let zoomLevel = parseInt(localStorage.getItem('examZoom') || '100', 10);

function applyZoom() {
    document.querySelector('.container').style.fontSize = zoomLevel + '%';
    document.getElementById('zoom-label').textContent = zoomLevel + '%';
    document.getElementById('zoom-out').disabled = zoomLevel <= ZOOM_MIN;
    document.getElementById('zoom-in').disabled  = zoomLevel >= ZOOM_MAX;
    localStorage.setItem('examZoom', zoomLevel);
}
function zoomIn()  { if (zoomLevel < ZOOM_MAX) { zoomLevel += ZOOM_STEP; applyZoom(); } }
function zoomOut() { if (zoomLevel > ZOOM_MIN) { zoomLevel -= ZOOM_STEP; applyZoom(); } }

function resetExam() {
    currentQuestionIndex = 0;
    userAnswers = [];
    document.getElementById("result-screen").style.display    = "none";
    document.getElementById("modal-overlay").style.display    = "none";
    document.getElementById("ai-generate-button").style.display = "none";
    document.getElementById("redo-incorrect-button").style.display = "none";
    loadQuestion();
}


function loadExamFromUrl(url) {
    fetch(url)
        .then(response => {
            if (!response.ok) throw new Error('Failed to load exam file');
            return response.text();
        })
        .then(text => {
            text = healthCheckAndFix(text);
            const { body } = parseFrontmatter(text);
            parseQuestions(body);
            document.getElementById('file-list-section').style.display = 'none';
            loadQuestion();
        })
        .catch(err => {
            alert('Error loading exam: ' + err.message);
        });
}

// Strip "[N] " prefix from numbered answer lines (AI-generated format),
// but leave markdown links like "[text](url)" untouched.
function healthCheckAndFix(text) {
    const lines = text.split('\n');
    const fixedLines = lines.map(line => {
        if (/^\s*\[\d+\]\s*-/.test(line)) {
            return line.replace(/^\s*\[\d+\]\s*/, '').trim();
        }
        return line;
    });
    return fixedLines.join('\n');
}

// ── Parser ────────────────────────────────────────────────────────────────────
// Rules (checked in order on each non-code-block line):
//   1. Line starts with `#`        → comment, skip entirely
//   2. Line starts with `---`      → section separator, skip (like a blank line)
//   3. Line starts with `-`        → answer choice
//   4. Line starts with ` ``` `    → opens a code block (part of question)
//   5. Inside a code block: everything belongs to the question until closing ` ``` `
//   6. Blank line                  → end of current question
//   7. Everything else             → question text (including `[link](url)` lines)
function parseQuestions(text) {
    questions = [];
    const lines = text.split('\n');
    let currentQuestion = null;
    let questionLines   = [];
    let inCodeBlock     = false;

    function flushQuestion() {
        if (currentQuestion) {
            currentQuestion.question = questionLines.join('\n').trim();
            if (currentQuestion.question || currentQuestion.answers.length > 0) {
                questions.push(currentQuestion);
            }
        }
        currentQuestion = null;
        questionLines   = [];
        inCodeBlock     = false;
    }

    for (const line of lines) {
        const trimmed = line.trim();

        // Inside a code block — everything goes into the question
        if (inCodeBlock) {
            questionLines.push(line);
            if (/^```\s*$/.test(trimmed)) {
                inCodeBlock = false;
            }
            continue;
        }

        // Comment line — skip silently
        if (trimmed.startsWith('#')) continue;

        // Section separator — skip (acts like a blank line but doesn't flush)
        if (trimmed.startsWith('---')) continue;

        // Blank line — close out the current question
        if (trimmed === '') {
            flushQuestion();
            continue;
        }

        // Opening of a code block (```lang or just ```)
        if (trimmed.startsWith('```')) {
            if (!currentQuestion) {
                currentQuestion = { question: '', answers: [], correct: [] };
            }
            questionLines.push(line);
            inCodeBlock = true;
            continue;
        }

        // Answer line
        if (line.startsWith('-')) {
            if (!currentQuestion) {
                currentQuestion = { question: '', answers: [], correct: [] };
            }
            const isCorrect  = line.startsWith('-*');
            const answerText = line.replace(/^-\*?/, '').trim();
            currentQuestion.answers.push({ text: answerText, isCorrect });
            if (isCorrect) {
                currentQuestion.correct.push(currentQuestion.answers.length - 1);
            }
            continue;
        }

        // Regular question text (plain text or [link](url) lines)
        if (!currentQuestion) {
            currentQuestion = { question: '', answers: [], correct: [] };
        }
        questionLines.push(line);
    }

    flushQuestion();
}

// ── Rendering ─────────────────────────────────────────────────────────────────
function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Returns the first meaningful line of question text (for result list summaries).
function getQuestionSummary(questionText) {
    for (const line of questionText.split('\n')) {
        const t = line.trim();
        if (t && !t.startsWith('```')) {
            return t.length > 100 ? t.substring(0, 100) + '\u2026' : t;
        }
    }
    return questionText.substring(0, 100);
}

// Full block renderer — handles mermaid diagrams, ```markdown unwrapping,
// standard markdown (via marked), and image-link → popup conversion.
function renderContent(rawText) {
    if (!rawText) return '';

    // Fall back to escaped plain text if marked is unavailable
    if (!window.marked) {
        return '<p>' + escapeHtml(rawText).replace(/\n/g, '<br>') + '</p>';
    }

    const mermaidMap = {};
    let counter = 0;

    // 1. Extract ```mermaid blocks — use a plain alphanumeric placeholder so
    //    marked doesn't interpret the token as markdown (e.g. ___x___ → <em><strong>)
    let processed = rawText.replace(/```mermaid\r?\n([\s\S]*?)\n?```/g, (_, code) => {
        const trimmedCode = code.trim();
        if (!trimmedCode) return '';          // skip empty mermaid blocks
        const key = `MERMAIDBLOCK${counter++}`;
        mermaidMap[key] = trimmedCode;
        return `\n\n${key}\n\n`;
    });

    // 2. Unwrap ```markdown blocks — render their contents as markdown
    processed = processed.replace(/```markdown\r?\n([\s\S]*?)\n?```/g, (_, content) => {
        return content.trim();
    });

    // 3. Render with marked
    let html = marked.parse(processed);

    // 4. Inject mermaid divs in place of the placeholder paragraphs
    for (const [key, code] of Object.entries(mermaidMap)) {
        // marked wraps the lone token in <p>…</p>; also catch it untagged
        const safeKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const placeholder = new RegExp(`<p>${safeKey}</p>|${safeKey}`, 'g');
        html = html.replace(placeholder, `<div class="mermaid">${escapeHtml(code)}</div>`);
    }

    // 5. Convert links whose href points to an image file into popup links
    html = html.replace(
        /href="([^"]+\.(?:png|jpg|jpeg|gif|svg|webp)(?:\?[^"]*)?)"/gi,
        (_, url) => `href="#" onclick="openImagePopup('${url.replace(/'/g, "\\'")}'); return false;"`
    );

    return html;
}

// Inline renderer for answer text (bold, italic, inline code, etc.)
function renderInline(text) {
    if (!text) return '';
    if (window.marked && marked.parseInline) {
        return marked.parseInline(text);
    }
    return escapeHtml(text);
}

// ── Question loader ───────────────────────────────────────────────────────────
async function loadQuestion() {
    const questionBlock  = document.getElementById("question-block");
    const answerBlock    = document.getElementById("answer-block");
    const currentQuestion = questions[currentQuestionIndex];

    document.getElementById("progress-text").textContent =
        `Question ${currentQuestionIndex + 1} of ${questions.length}`;

    questionBlock.innerHTML = renderContent(currentQuestion.question);
    answerBlock.innerHTML   = "";

    currentQuestion.answers.forEach((answer, index) => {
        const inputType = currentQuestion.correct.length > 1 ? "checkbox" : "radio";
        const isChecked = userAnswers[currentQuestionIndex]?.includes(index);
        answerBlock.innerHTML += `
            <label class="${answer.isCorrect ? 'correct-answer' : ''}">
                <input type="${inputType}" name="answer" value="${index}" ${isChecked ? 'checked' : ''}>
                ${renderInline(answer.text)}
            </label>
        `;
    });

    document.getElementById("prev-button").disabled = currentQuestionIndex === 0;
    document.getElementById("next-button").disabled = currentQuestionIndex === questions.length - 1;

    toggleCorrectAnswers();

    // Render any mermaid diagrams in the question
    if (window.mermaid) {
        const nodes = Array.from(questionBlock.querySelectorAll('.mermaid'));
        if (nodes.length > 0) {
            for (const node of nodes) {
                const source = node.textContent.trim();
                try {
                    const id = 'mermaid-' + Math.random().toString(36).slice(2);
                    const { svg } = await mermaid.render(id, source);
                    node.innerHTML = svg;
                } catch (e) {
                    console.warn('Mermaid rendering error:', e);
                    node.innerHTML = '<pre style="color:#f88;font-size:0.85em">' + escapeHtml(source) + '</pre>';
                }
            }
        }
    }
}

function openImagePopup(imageUrl) {
    const popupOverlay = document.createElement('div');
    popupOverlay.style.cssText =
        'position:fixed;top:0;left:0;width:100vw;height:100vh;' +
        'background:rgba(0,0,0,0.85);display:flex;justify-content:center;' +
        'align-items:center;z-index:1000;cursor:pointer';

    const imageElement = document.createElement('img');
    imageElement.src = imageUrl;
    imageElement.style.cssText = 'max-width:90%;max-height:90%;border:2px solid white;border-radius:4px';
    popupOverlay.appendChild(imageElement);

    popupOverlay.addEventListener('click', () => document.body.removeChild(popupOverlay));
    document.body.appendChild(popupOverlay);
}

function toggleCorrectAnswers() {
    const showCorrect   = document.getElementById("showCorrectAnswers").checked;
    const correctLabels = document.querySelectorAll("label.correct-answer");
    correctLabels.forEach(label => label.classList.toggle("highlighted", showCorrect));
}

function showResults() {
    const resultScreen = document.getElementById("result-screen");
    const scoreElement = document.getElementById("score");
    const resultList   = document.getElementById("result-list");

    let correctCount = 0;
    resultList.innerHTML = "";

    questions.forEach((question, index) => {
        const userAnswer = userAnswers[index] || [];
        const isCorrect  = JSON.stringify(userAnswer.sort()) === JSON.stringify(question.correct.sort());
        if (isCorrect) correctCount++;

        const resultItem = document.createElement("li");
        resultItem.textContent = `Q${index + 1}: ${getQuestionSummary(question.question)}`;
        resultItem.className   = isCorrect ? "correct" : "wrong";
        resultItem.addEventListener("click", () => {
            currentQuestionIndex = index;
            loadQuestion();
            document.getElementById("result-screen").style.display = "none";
        });
        resultList.appendChild(resultItem);
    });

    scoreElement.textContent =
        `You scored ${correctCount} out of ${questions.length} (${Math.round((correctCount / questions.length) * 100)}%)`;
    resultScreen.style.display = "block";

    generateHiddenResult();
    document.getElementById("redo-incorrect-button").style.display = "inline-block";
    document.getElementById("ai-generate-button").style.display    = "inline-block";
}

function generateHiddenResult() {
    let resultOutput = "";
    questions.forEach((question, index) => {
        resultOutput += `${question.question}\n`;
        question.answers.forEach((answer, answerIndex) => {
            const userSelected = userAnswers[index]?.includes(answerIndex) ? "[*] " : "[ ] ";
            const correctMark  = question.correct.includes(answerIndex) ? "-*" : "-";
            resultOutput += `${userSelected}${correctMark} ${answer.text}\n`;
        });
        resultOutput += "\n";
    });
    document.getElementById("hidden-output").textContent = resultOutput;
}

function toggleResults() {
    const modalOverlay = document.getElementById("modal-overlay");
    const isHidden = modalOverlay.style.display === "none" || modalOverlay.style.display === "";
    modalOverlay.style.display = isHidden ? "flex" : "none";
}

function copyToClipboard() {
    const textToCopy = document.getElementById("hidden-output").textContent;
    navigator.clipboard.writeText(textToCopy)
        .then(() => alert("Copied to clipboard!"))
        .catch(err => alert("Failed to copy text: " + err));
}

// ── Base64 UTF-8 helpers ──────────────────────────────────────────────────
// Standard btoa/atob only handle Latin-1; these handle full UTF-8.
function textToBase64(str) {
    return btoa(encodeURIComponent(str).replace(/%([0-9A-F]{2})/gi,
        (_, p1) => String.fromCharCode(parseInt(p1, 16))));
}
function base64ToText(b64) {
    return decodeURIComponent(
        atob(b64).split('').map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join('')
    );
}

// ── AI generator ──────────────────────────────────────────────────────────
// Encodes current exam results as base64 and opens the AI generator page.
function openAiGenerator() {
    const text = document.getElementById("hidden-output").textContent;
    window.location.href = 'ai/?input=' + encodeURIComponent(textToBase64(text));
}

// ── Event listeners ───────────────────────────────────────────────────────────
document.getElementById("next-button").addEventListener("click", () => {
    const selectedAnswers = Array.from(
        document.querySelectorAll("input[name='answer']:checked")
    ).map(input => parseInt(input.value));
    userAnswers[currentQuestionIndex] = selectedAnswers;

    if (currentQuestionIndex < questions.length - 1) {
        currentQuestionIndex++;
        loadQuestion();
    }
});

document.getElementById("prev-button").addEventListener("click", () => {
    if (currentQuestionIndex > 0) {
        currentQuestionIndex--;
        loadQuestion();
    }
});

document.getElementById("end-exam-button").addEventListener("click", () => {
    const selectedAnswers = Array.from(
        document.querySelectorAll("input[name='answer']:checked")
    ).map(input => parseInt(input.value));
    userAnswers[currentQuestionIndex] = selectedAnswers;
    showResults();
});

document.getElementById("reset-button").addEventListener("click", resetExam);
document.getElementById("back-button").addEventListener("click", () => {
    window.location.href = window.location.pathname;
});

document.getElementById("redo-incorrect-button").addEventListener("click", () => {
    questions = questions.filter((question, index) => {
        const userAnswer = userAnswers[index] || [];
        return JSON.stringify(userAnswer.sort()) !== JSON.stringify(question.correct.sort());
    });
    userAnswers = [];
    currentQuestionIndex = 0;
    loadQuestion();
    document.getElementById("result-screen").style.display = "none";
    document.getElementById("redo-incorrect-button").style.display = "none";
});

document.getElementById("showCorrectAnswers").addEventListener("change", toggleCorrectAnswers);

// ── Boot ──────────────────────────────────────────────────────────────────────
window.onload = function() {
    applyZoom();
    const urlParams = new URLSearchParams(window.location.search);

    const filePath = urlParams.get('file');
    if (filePath) {
        // Accept both ?file=demo.sef and legacy ?file=content/demo.sef
        const url = filePath.startsWith('content/') ? filePath : 'content/' + filePath;
        loadExamFromUrl(url);
        return;
    }

    const base64Text = urlParams.get('exam');
    if (base64Text) {
        try {
            const fixed = healthCheckAndFix(base64ToText(base64Text));
            const { body } = parseFrontmatter(fixed);
            parseQuestions(body);
            document.getElementById('file-list-section').style.display = 'none';
            loadQuestion();
        } catch (e) {
            console.error("Invalid base64 string in URL parameter.");
        }
    }
};
