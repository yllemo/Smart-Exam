# Smart Exam

A lightweight, self-hosted exam simulator built around the [Smart Exam Format (.sef)](https://github.com/yllemo/Smart-Exam-Format). Drop plain-text question files into a folder, and the app serves them as interactive multiple-choice quizzes — no database, no build step, no dependencies beyond PHP.

## Features

- Dark-themed, responsive UI
- Single and multiple correct answer support (radio buttons / checkboxes)
- "Show Answer" toggle to reveal correct answers mid-exam
- Progress indicator and per-question navigation
- Score summary with clickable per-question results
- Redo incorrect answers to focus on weak spots
- Image and link support via markdown-style syntax in questions and answers
- URL-based exam loading — share an exam with `?file=` or embed content with `?exam=`
- Password-protected admin panel to create and manage exam files from the browser

## Project Structure

```
/
├── index.php            # Exam simulator (reads config, lists .sef files)
├── app.js               # Exam logic (parsing, navigation, scoring)
├── style.css            # Stylesheet
├── config/
│   ├── config.json      # Site title, favicon, stylesheet, description
│   └── admin.json       # Admin password hash (bcrypt)
├── content/
│   └── *.sef            # Exam files served on the home screen
└── admin/
    └── index.php        # Admin panel (login, file list, SEF editor)
```

## Setup

1. Clone or download this repository
2. Place your `.sef` exam files in the `content/` directory
3. Serve with a PHP-capable web server (Apache, Nginx, or PHP's built-in server)
4. Open `index.php` in your browser — available exams are listed automatically

### Quick start with PHP built-in server

```bash
php -S localhost:8000
```

Then visit `http://localhost:8000`.

## Configuration

Edit `config/config.json` to customise the application:

```json
{
  "title": "Smart Exam Simulator",
  "favicon": "favicon.ico",
  "stylesheet": "style.css",
  "description": "Interactive exam simulator powered by Smart Exam Format"
}
```

| Key | Description |
|-----|-------------|
| `title` | Browser tab title and page heading |
| `favicon` | Path to favicon file (leave empty to omit) |
| `stylesheet` | Path to CSS stylesheet |
| `description` | Meta description for the page |

## Admin Panel

Visit `/admin/` to open the password-protected admin panel.

On first visit you will be prompted to set an admin password — it is stored as a bcrypt hash in `config/admin.json`. From the admin panel you can:

- Browse, create, edit, and delete `.sef` files in `content/`
- Use the built-in **Question Builder** to compose and parse questions step by step before adding them to an exam
- Read the **SEF Format help page** with syntax examples and a link to the full specification

## Adding Exams

Drop any `.sef` file into the `content/` directory, or use the admin panel to create one directly. Files appear automatically on the home screen.

## Smart Exam Format

Exam files use the [Smart Exam Format (.sef)](https://github.com/yllemo/Smart-Exam-Format) — a plain-text format for writing multiple-choice questions.

### Syntax

```
Question text goes here
-* Correct answer
-  Wrong answer
-  Wrong answer
-  Wrong answer

Another question (multiple correct = checkboxes)
-* First correct answer
-* Second correct answer
-  Wrong answer
-  Wrong answer
```

- Lines prefixed with `-*` are correct answers
- Lines prefixed with `-` are incorrect answers
- Question blocks are separated by a blank line
- Multiple `-*` lines on one question automatically switch the UI to checkboxes

### Images and links

Markdown-style links work inside question and answer text:

```
What does this diagram show? [View diagram](images/chart.png)
-* A bar chart
-  A pie chart
```

Images open in a lightbox overlay when clicked.

## URL Parameters

| Parameter | Description | Example |
|-----------|-------------|---------|
| `file` | Load a `.sef` file by server path | `?file=content/myexam.sef` |
| `exam` | Load an exam from base64-encoded SEF text | `?exam=<base64>` |

---

## Ideas for Future Development

### AI-Generated Questions
Integrate an LLM API (e.g. Claude, GPT) to generate `.sef` question sets from a topic, a document, or a URL. The admin panel could include a prompt field that sends a request and writes the response directly into a new `.sef` file, ready for review and publishing.

### Adaptive Learning / Diminishing Returns
Track answer history per question (in `localStorage` or a lightweight backend) and apply a spaced-repetition or diminishing-returns algorithm. Questions answered correctly multiple times in a row appear less frequently; recently failed questions surface more often. This would turn the simulator into a genuine learning tool rather than a one-shot practice test.

### Markdown and Mermaid Support
Extend the question and answer renderer to parse full Markdown (bold, italic, code blocks, tables) and render [Mermaid](https://mermaid.js.org/) diagram definitions inline. This would allow questions that include flowcharts, sequence diagrams, ER diagrams, and other visual content — useful for technical and systems-design exams.

### Themes — Dark / Light Mode and Custom Styles
Add a theme switcher (persisted in `localStorage`) and ship at least a light mode alongside the current dark theme. The `stylesheet` config key already supports swapping the CSS file entirely, so named theme bundles (e.g. `theme-light.css`, `theme-high-contrast.css`) could be selectable from the config or via a UI toggle.

### Multilingual Support
Add a `locale` key to `config/config.json` to localise UI strings (button labels, progress text, result messages). Question files could carry a language tag in their filename (e.g. `networking-fr.sef`) and the home screen could filter or group by language. Right-to-left language support would require a small CSS addition (`dir="rtl"`).

---

## Links

- [Smart Exam Format specification](https://github.com/yllemo/Smart-Exam-Format) — full `.sef` syntax reference and examples
