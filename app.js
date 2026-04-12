let questions = [];
let currentQuestionIndex = 0;
let userAnswers = [];

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
    document.getElementById("result-screen").style.display = "none";
    document.getElementById("modal-overlay").style.display = "none";
    loadQuestion();
}

function backToList() {
    questions = [];
    currentQuestionIndex = 0;
    userAnswers = [];
    document.getElementById("result-screen").style.display = "none";
    document.getElementById("modal-overlay").style.display = "none";
    document.getElementById("question-block").innerHTML = "";
    document.getElementById("answer-block").innerHTML = "";
    document.getElementById("progress-info").innerHTML = "";
    document.getElementById("file-list-section").style.display = "block";
}

function loadExamFromUrl(url) {
    fetch(url)
        .then(response => {
            if (!response.ok) throw new Error('Failed to load exam file');
            return response.text();
        })
        .then(text => {
            text = healthCheckAndFix(text);
            parseQuestions(text);
            document.getElementById('file-list-section').style.display = 'none';
            loadQuestion();
        })
        .catch(err => {
            alert('Error loading exam: ' + err.message);
        });
}

function healthCheckAndFix(text) {
    const lines = text.split('\n');
    const fixedLines = lines.map(line => {
        if (line.trim().startsWith('[')) {
            return line.replace(/\[.*?\]\s*(-\*?)\s*/, '$1').trim();
        }
        return line;
    });
    return fixedLines.join('\n');
}

function parseQuestions(text) {
    questions = [];
    const lines = text.split('\n');
    let currentQuestion = null;

    lines.forEach(line => {
        if (line.trim() === '') return;

        if (!line.startsWith('-')) {
            if (currentQuestion) questions.push(currentQuestion);
            currentQuestion = {
                question: line.trim(),
                answers: [],
                correct: []
            };
        } else {
            const isCorrect = line.startsWith('-*');
            const answerText = line.replace(/^-\*?/, '').trim();
            currentQuestion.answers.push({ text: answerText, isCorrect: isCorrect });
            if (isCorrect) {
                currentQuestion.correct.push(currentQuestion.answers.length - 1);
            }
        }
    });

    if (currentQuestion) questions.push(currentQuestion);
}

function loadQuestion() {
    const questionBlock = document.getElementById("question-block");
    const answerBlock = document.getElementById("answer-block");
    const currentQuestion = questions[currentQuestionIndex];

    document.getElementById("progress-text").textContent = `Question ${currentQuestionIndex + 1} of ${questions.length}`;
    questionBlock.innerHTML = formatMarkdown(currentQuestion.question);
    answerBlock.innerHTML = "";

    currentQuestion.answers.forEach((answer, index) => {
        const inputType = currentQuestion.correct.length > 1 ? "checkbox" : "radio";
        const isChecked = userAnswers[currentQuestionIndex] && userAnswers[currentQuestionIndex].includes(index);
        answerBlock.innerHTML += `
            <label class="${answer.isCorrect ? 'correct-answer' : ''}">
                <input type="${inputType}" name="answer" value="${index}" ${isChecked ? 'checked' : ''}>
                ${formatMarkdown(answer.text)}
            </label>
        `;
    });

    document.getElementById("prev-button").disabled = currentQuestionIndex === 0;
    document.getElementById("next-button").disabled = currentQuestionIndex === questions.length - 1;

    toggleCorrectAnswers();
}

function formatMarkdown(text) {
    const markdownImageRegex = /\[([^\]]+)\]\(([^\)]+)\)/g;
    return text.replace(markdownImageRegex, '<a href="#" onclick="openImagePopup(\'$2\')">$1</a>');
}

function openImagePopup(imageUrl) {
    const popupOverlay = document.createElement('div');
    popupOverlay.style.position = 'fixed';
    popupOverlay.style.top = '0';
    popupOverlay.style.left = '0';
    popupOverlay.style.width = '100vw';
    popupOverlay.style.height = '100vh';
    popupOverlay.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
    popupOverlay.style.display = 'flex';
    popupOverlay.style.justifyContent = 'center';
    popupOverlay.style.alignItems = 'center';
    popupOverlay.style.zIndex = '1000';
    popupOverlay.style.cursor = 'pointer';

    const imageElement = document.createElement('img');
    imageElement.src = imageUrl;
    imageElement.style.maxWidth = '90%';
    imageElement.style.maxHeight = '90%';
    imageElement.style.border = '2px solid white';
    popupOverlay.appendChild(imageElement);

    popupOverlay.addEventListener('click', () => {
        document.body.removeChild(popupOverlay);
    });

    document.body.appendChild(popupOverlay);
}

function toggleCorrectAnswers() {
    const showCorrect = document.getElementById("showCorrectAnswers").checked;
    const correctLabels = document.querySelectorAll("label.correct-answer");
    correctLabels.forEach(label => {
        if (showCorrect) {
            label.classList.add("highlighted");
        } else {
            label.classList.remove("highlighted");
        }
    });
}

function showResults() {
    const resultScreen = document.getElementById("result-screen");
    const scoreElement = document.getElementById("score");
    const resultList = document.getElementById("result-list");

    let correctCount = 0;
    resultList.innerHTML = "";

    questions.forEach((question, index) => {
        const userAnswer = userAnswers[index] || [];
        const isCorrect = JSON.stringify(userAnswer.sort()) === JSON.stringify(question.correct.sort());
        if (isCorrect) correctCount++;

        const resultItem = document.createElement("li");
        resultItem.textContent = `Q${index + 1}: ${question.question}`;
        resultItem.className = isCorrect ? "correct" : "wrong";
        resultItem.addEventListener("click", () => {
            currentQuestionIndex = index;
            loadQuestion();
            document.getElementById("result-screen").style.display = "none";
        });
        resultList.appendChild(resultItem);
    });

    scoreElement.textContent = `You scored ${correctCount} out of ${questions.length} (${Math.round((correctCount / questions.length) * 100)}%)`;
    resultScreen.style.display = "block";

    generateHiddenResult();
    document.getElementById("redo-incorrect-button").style.display = "inline-block";
}

function generateHiddenResult() {
    let resultOutput = "";
    questions.forEach((question, index) => {
        resultOutput += `${question.question}\n`;
        question.answers.forEach((answer, answerIndex) => {
            const userSelected = userAnswers[index] && userAnswers[index].includes(answerIndex) ? "[*] " : "[ ] ";
            const correctMark = question.correct.includes(answerIndex) ? "-*" : "-";
            resultOutput += `${userSelected}${correctMark} ${answer.text}\n`;
        });
        resultOutput += "\n";
    });

    document.getElementById("hidden-output").textContent = resultOutput;
}

function toggleResults() {
    const modalOverlay = document.getElementById("modal-overlay");
    if (modalOverlay.style.display === "none" || modalOverlay.style.display === "") {
        modalOverlay.style.display = "flex";
    } else {
        modalOverlay.style.display = "none";
    }
}

function copyToClipboard() {
    const textToCopy = document.getElementById("hidden-output").textContent;
    navigator.clipboard.writeText(textToCopy).then(() => {
        alert("Copied to clipboard!");
    }).catch(err => {
        alert("Failed to copy text: " + err);
    });
}

document.getElementById("next-button").addEventListener("click", () => {
    const selectedAnswers = Array.from(document.querySelectorAll("input[name='answer']:checked")).map(input => parseInt(input.value));
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
    const selectedAnswers = Array.from(document.querySelectorAll("input[name='answer']:checked")).map(input => parseInt(input.value));
    userAnswers[currentQuestionIndex] = selectedAnswers;
    showResults();
});

document.getElementById("reset-button").addEventListener("click", resetExam);
document.getElementById("back-button").addEventListener("click", backToList);

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

// Load exam from URL parameters on page load
window.onload = function() {
    applyZoom();
    const urlParams = new URLSearchParams(window.location.search);

    // ?file=content/myexam.sef  — load a .sef file by path
    const filePath = urlParams.get('file');
    if (filePath) {
        loadExamFromUrl(filePath);
        return;
    }

    // ?exam=<base64>  — load exam from base64-encoded text
    const base64Text = urlParams.get('exam');
    if (base64Text) {
        try {
            const decodedText = atob(base64Text);
            parseQuestions(healthCheckAndFix(decodedText));
            document.getElementById('file-list-section').style.display = 'none';
            loadQuestion();
        } catch (e) {
            console.error("Invalid base64 string in URL parameter.");
        }
    }
};
