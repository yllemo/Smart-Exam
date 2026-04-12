# Smart Exam

A lightweight, self-hosted exam simulator built around the [Smart Exam Format (.sef)](https://github.com/yllemo/Smart-Exam-Format). Drop plain-text question files into a folder, and the app serves them as interactive multiple-choice quizzes — no database, no build step, no dependencies beyond PHP.

## Features

- Dark-themed, mobile-responsive UI
- Single and multiple correct answer support (radio buttons / checkboxes)
- Font size zoom control for comfortable reading on any screen
- "Show Answer" toggle to reveal correct answers mid-exam
- Progress indicator and per-question navigation
- Score summary with clickable per-question results
- Redo incorrect answers to focus on weak spots
- Image and link support via markdown-style syntax in questions and answers
- URL-based exam loading — share with `?file=` or embed content with `?exam=`
- Password-protected admin panel to create and manage exam files from the browser

## Requirements

- PHP 7.4 or later
- A web server with PHP support (Apache, Nginx) or PHP's built-in server

## Quick Start

```bash
git clone https://github.com/yllemo/Smart-Exam.git
cd Smart-Exam
php -S localhost:8000
```

Open `http://localhost:8000` in your browser. Place `.sef` files in `content/` and they appear on the home screen automatically.

## Project Structure

```
/
├── index.php                  # Exam simulator — lists exams, reads config
├── app.js                     # Exam logic (parsing, navigation, scoring, zoom)
├── style.css                  # Stylesheet (dark theme, responsive)
├── favicon.svg                # Default SVG favicon
├── AI.md                      # SEF format guide for AI integrations
├── config/
│   ├── config.json            # Site title, favicon, stylesheet, description
│   └── admin.json_example     # Template for admin credentials (see Admin Panel)
├── content/
│   └── *.sef                  # Exam files served on the home screen
└── admin/
    ├── index.php              # Admin panel (login, file list, SEF editor, prompt helper)
    └── SEF_Exam_Editor.html   # Standalone offline SEF editor (no server required)
```

> `config/admin.json` is excluded from the repository (see `.gitignore`). It is created automatically from `admin.json_example` on first access and holds the bcrypt password hash.

## Configuration

Edit `config/config.json` to customise the application:

```json
{
  "title": "Smart Exam",
  "favicon": "favicon.svg",
  "stylesheet": "style.css",
  "description": "Interactive exam simulator powered by Smart Exam Format (.sef)"
}
```

| Key | Description |
|-----|-------------|
| `title` | Browser tab title and page heading |
| `favicon` | Path to favicon file — SVG recommended |
| `stylesheet` | Path to CSS stylesheet (swap for custom themes) |
| `description` | Meta description for the page |

## Admin Panel

Visit `/admin/` to manage your exam library.

On first visit you are prompted to set an admin password. It is stored as a bcrypt hash in `config/admin.json` (gitignored — never overwritten by deployments).

### Features

| Section | Description |
|---------|-------------|
| **File List** | Browse all `.sef` files with size and last-modified date. Edit or delete any file. |
| **Editor** | Split-pane editor — Question Builder on the left, raw `.sef` content on the right. Parse answer prefixes automatically, then save directly to `content/`. |
| **SEF Format Help** | Inline syntax reference with coloured examples and a link to the full specification. |
| **Prompt Helper** | Generates a ready-to-copy AI prompt (for Claude, ChatGPT, Gemini, etc.) that includes the full SEF format rules and your topic, difficulty, question count, and language. Paste the output straight into an AI chat to get a correctly formatted `.sef` file back. |
| **Change Password** | Update the admin password at any time. |

## Smart Exam Format

Exam files use the [Smart Exam Format (.sef)](https://github.com/yllemo/Smart-Exam-Format) — a plain-text format for writing multiple-choice questions.

### Syntax

```
Question text goes here
-* Correct answer
-  Wrong answer
-  Wrong answer

Another question — multiple correct answers trigger checkboxes
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
The admin **Prompt Helper** already generates structured prompts for any AI assistant. A natural next step is a direct API integration — send a topic to an LLM and have the response written straight into a new `.sef` file ready for review.

### Adaptive Learning / Diminishing Returns
Track answer history per question (in `localStorage` or a lightweight backend) and apply a spaced-repetition algorithm. Questions answered correctly multiple times appear less frequently; recently failed questions surface more often — turning the simulator into a genuine study tool.

### Markdown and Mermaid Support
Extend the renderer to parse full Markdown (bold, italic, code blocks, tables) and render [Mermaid](https://mermaid.js.org/) diagrams inline. This would enable questions containing flowcharts, sequence diagrams, and ER diagrams — useful for technical and systems-design exams.

### Themes — Dark / Light Mode and Custom Styles
Add a theme switcher persisted in `localStorage`. The `stylesheet` key in `config.json` already supports swapping the CSS file entirely, so named theme bundles (`theme-light.css`, `theme-high-contrast.css`) could be selectable from the config or via a UI toggle.

### Multilingual Support
Add a `locale` key to `config/config.json` to localise UI strings. Question files could carry a language tag in their filename (`networking-fr.sef`) and the home screen could filter or group by language. RTL support requires a small CSS addition.

---

## Links

- [Smart Exam Format specification](https://github.com/yllemo/Smart-Exam-Format) — full `.sef` syntax reference, AI integration guide, and examples
