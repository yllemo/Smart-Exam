# AI Instructions for Smart Exam Format (SEF)

This document describes the **Smart Exam Format (SEF)** for AI systems that parse, generate, or transform `.sef` exam files.

---

## Format Overview

SEF is a plain-text format for multiple-choice exams. Files use the `.sef` extension and UTF-8 encoding. Questions and answers are grouped in **blocks separated by blank lines**.

---

## 1. YAML Frontmatter (optional)

A frontmatter block at the very top of the file provides metadata. It is stripped before parsing and never shown during the exam.

```
---
name: Exam Title
description: A short description shown on the start page
---
```

Supported keys: `name`, `description`. Only simple `key: value` pairs are supported (no nested YAML).

---

## 2. Comments and Section Separators

```
# This is a comment — ignored by the parser
--- Section label (also ignored) ---
```

- Lines starting with `#` are comments, stripped silently.
- Lines starting with `---` are visual section separators, also stripped.
- Use them freely to organise the file.

---

## 3. Question Blocks

Each block is separated from the next by **one or more blank lines**.

```
Question text goes here
- Wrong answer
-* Correct answer
- Wrong answer
- Wrong answer
```

Rules:
- Any non-blank, non-comment, non-separator line that does **not** start with `-` is question text.
- Lines starting with `-` are answer choices.
- Lines starting with `-*` are **correct** answer choices.
- A block with **multiple `-*` lines** is rendered as checkboxes (multiple correct answers allowed).
- A block with **one `-*` line** is rendered as radio buttons.

---

## 4. Markdown in Questions

Full Markdown is supported in question text (rendered via marked.js):

```
What does the `**bold**` syntax produce in Markdown?
- Italic text
-* Bold text
- Underlined text
- A heading
```

Supported: **bold**, *italic*, `inline code`, [links](url), ![images](url), ordered/unordered lists, blockquotes.

> Raw HTML tags are intentionally **escaped** and shown as literal text, so `<title>` or `<h1>` in an answer option will display as text, not as a live HTML element.

---

## 5. Inline Images

Use standard Markdown image syntax to embed images directly in a question:

```
Identify the shape shown below:
![A blue triangle](content/images/triangle.png)
-* Triangle
- Square
- Circle
```

Use `![alt](path)` for inline display. Paths are relative to the site root.

Image links in the form `[label](path.png)` open in a popup overlay instead.

---

## 6. Code Blocks Inside Questions

Fenced code blocks (` ``` `) are part of the question block and rendered with syntax highlighting:

```
What does this Python snippet print?
` ``python
x = [i**2 for i in range(4)]
print(x)
` ``
- [1, 4, 9, 16]
-* [0, 1, 4, 9]
- [0, 1, 2, 3]
- SyntaxError
```

The closing ` ``` ` ends the code block but does **not** end the question block. Answer lines can follow immediately.

---

## 7. Mermaid Diagrams

Use a ` ```mermaid ``` ` block anywhere in the question text to embed a rendered diagram:

```
` ``mermaid
flowchart TD
    A[Client] -->|HTTP Request| B[Server]
    B -->|Response| A
` ``
What protocol does this diagram illustrate?
- FTP
-* HTTP
- SMTP
- SSH
```

The diagram is rendered interactively by mermaid.js. Diagrams are a powerful way to ask questions about architecture, flows, and data structures.

---

## 8. Complete Example File

```
---
name: Web Fundamentals
description: HTTP, REST, and browser basics
---

# ── HTTP ──────────────────────────────────────────────────────────────────────

What does **HTTP** stand for?
- HyperText Transfer Method
-* HyperText Transfer Protocol
- High Transfer Text Protocol
- Host-to-Host Transport Protocol

Which HTTP status codes indicate a **client** error? (select all that apply)
-* 400 Bad Request
-* 403 Forbidden
-* 404 Not Found
- 200 OK
- 500 Internal Server Error

` ``mermaid
flowchart LR
    Client -->|Request| Server
    Server -->|200 OK| A[Success]
    Server -->|4xx| B[Client Error]
    Server -->|5xx| C[Server Error]
` ``
What category does HTTP 404 fall into in this diagram?
- Server Error
-* Client Error
- Success
- Redirect

# ── REST ──────────────────────────────────────────────────────────────────────

Which HTTP methods are considered **safe** (read-only)? (select all)
-* GET
-* HEAD
- POST
- DELETE
-* OPTIONS
```

---

## 9. Executed State (Results Format)

After a user completes an exam, results are exported in **Executed State** format. Each answer line is prefixed with the user's selection:

```
What does HTTP stand for?
[ ] - HyperText Transfer Method
[*] -* HyperText Transfer Protocol
[ ] - High Transfer Text Protocol
[ ] - Host-to-Host Transport Protocol
```

**Interpretation table:**

| Prefix + Marker | Meaning |
|----------------|---------|
| `[*] -*` | User selected a correct answer ✓ |
| `[ ] -*` | User missed a correct answer ✗ (knowledge gap) |
| `[*] -`  | User selected a wrong answer ✗ (misconception) |
| `[ ] -`  | User correctly avoided a wrong answer ✓ |

---

## 10. AI Generation Guidelines

### Output rules
- Output **only valid SEF** — no explanations, no wrapping code fences, no extra commentary.
- Start with YAML frontmatter (`---\nname: …\ndescription: …\n---`).
- Separate every question block with exactly **one blank line**.
- Use `#` comment lines and `---` separators to organise sections.

### Analysing results for follow-up questions
1. Identify questions where the user had `[ ] -*` (missed correct) or `[*] -` (selected wrong).
2. Group by topic if discernible.
3. Generate new questions that approach those topics from a different angle.
4. For `[ ] -*`: reinforce the missed concept with similar questions.
5. For `[*] -`: expose the misconception directly in a new question.

### Vary question types
- Single-answer (one `-*`)
- Multiple-answer (several `-*` — remind users to "select all that apply")
- Code-based (` ```lang ``` ` snippet in the question)
- Diagram-based (` ```mermaid ``` ` for flows, architectures, state machines)

### Language
- Always match the language of the original questions.
- Do not translate unless explicitly asked.

---

## 11. URL Parameters (for reference)

| Parameter | Description |
|-----------|-------------|
| `?file=demo.sef` | Load a `.sef` file from `/content/` |
| `?exam=<base64>` | Load a full SEF file encoded as UTF-8-safe base64 |
| `/ai/?input=<base64>` | Open the AI generator with pre-loaded exam results |

Base64 encoding uses UTF-8 bytes encoded with the standard `encodeURIComponent` → `btoa` pattern, so international characters (Swedish å/ä/ö, etc.) are handled correctly.
