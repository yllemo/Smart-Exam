# AI Instructions for Smart Exam Format (SEF)

This document provides comprehensive instructions for AI systems working with the **Smart Exam Format (SEF)** - a structured plain text format for creating, managing, and analyzing multiple-choice exams.

---

## What is Smart Exam Format (SEF)?

SEF is a free, open-source plain text format (.txt) designed for:
- Creating adaptive learning experiences
- Tracking user performance and progress
- Enabling AI-powered question generation
- Supporting continuous learning cycles
- Providing compatibility across all devices and platforms

SEF's core innovation is its **two-state design** that transforms from Input State (original exam) to Executed State (with user responses), enabling detailed performance analysis and AI-driven follow-up learning.

---

## SEF Format Structure

### 1. Input State (Original Exam Format)

The Input State is used for presenting exams to learners:

```
What is 2 + 2?
-* 4
- 3
- 5

What is the capital of France?
-* Paris
- Berlin
- Madrid

Which colors are primary colors?
-* Red
-* Blue
-* Yellow
- Green
- Orange
```

**Key Rules for Input State:**
- Each question starts with question text on its own line
- Answer options start with hyphen (`-`)
- Correct answers are marked with hyphen-asterisk (`-*`)
- Questions are separated by blank lines
- Multiple correct answers are supported
- Images can be included using Markdown syntax: `![alt text](image-url)` or `[alt text](image-url)`

### 2. Executed State (With User Responses)

The Executed State captures user selections for analysis:

```
What is 2 + 2?
[] -* 4
[] - 3
[*] - 5

What is the capital of France?
[*] -* Paris
[] - Berlin
[] - Madrid

Which colors are primary colors?
[*] -* Red
[] -* Blue
[*] -* Yellow
[] - Green
[*] - Orange
```

**Key Rules for Executed State:**
- User selections are marked with `[*]` (selected) or `[]` (not selected)
- Correct answers maintain their `-*` marking
- Format: `[selection] -[correctness] answer_text`
- Selection: `[*]` = selected, `[]` = not selected
- Correctness: `-*` = correct answer, `-` = incorrect answer

---

## Response Pattern Analysis

When analyzing SEF Executed State, identify these patterns:

| Pattern | Format | Meaning |
|---------|--------|---------|
| `[*] -*` | Selected Correct | User chose correct answer |
| `[] -*` | Unselected Correct | User missed correct answer |
| `[*] -` | Selected Incorrect | User chose wrong answer |
| `[] -` | Unselected Incorrect | User correctly avoided wrong answer |

**Focus Areas for AI Follow-up:**
1. `[] -*` patterns indicate knowledge gaps (missed correct answers)
2. `[*] -` patterns indicate misconceptions (chose incorrect answers)
3. Questions with multiple `[] -*` or `[*] -` patterns need reinforcement

---

## AI Guidelines for Working with SEF

### Parsing SEF Files

When parsing SEF content:

1. **Identify State Type:**
   - Input State: Contains `-*` and `-` markings only
   - Executed State: Contains `[]` and `[*]` markings

2. **Extract Questions:**
   - Questions are separated by blank lines
   - First line of each block is the question text
   - Subsequent lines starting with `-` or `[]` are answers

3. **Parse Answer Options:**
   - Split on newlines, filter for lines starting with `-` or `[]`
   - Extract selection status, correctness, and answer text

### Generating SEF Questions

When creating new SEF questions:

1. **Question Format:**
   ```
   [Question text here]
   -* [Correct answer 1]
   -* [Correct answer 2] (if multiple correct)
   - [Incorrect answer 1]
   - [Incorrect answer 2]
   ```

2. **Best Practices:**
   - Write clear, unambiguous questions
   - Provide 3-5 answer options
   - Ensure only correct answers have `-*` marking
   - Include at least 2-3 incorrect options for proper difficulty
   - Separate questions with blank lines

3. **For Images:**
   ```
   What type of animal is shown?
   ![Animal image](https://example.com/image.jpg)
   -* Cat
   - Dog
   - Bird
   ```

### Analyzing Performance for Follow-up Questions

When analyzing Executed State for generating follow-up questions:

1. **Identify Weak Areas:**
   - Count `[] -*` (missed correct) and `[*] -` (selected incorrect) patterns
   - Group by topic or subject area if metadata available

2. **Generate Targeted Questions:**
   - Create questions focusing on missed concepts
   - Approach the same topic from different angles
   - Vary question difficulty based on performance level

3. **Reinforcement Strategy:**
   - For `[] -*`: Create similar questions about the same concept
   - For `[*] -`: Create questions that clarify common misconceptions
   - Focus on topics with multiple errors

### Creating Effective SEF Prompts

When generating AI prompts for SEF question creation:

```
Create new Smart Exam Format questions based on this analysis:

MISSED TOPICS ([] -*):
- Topic 1: [specific concept missed]
- Topic 2: [specific concept missed]

MISCONCEPTIONS ([*] -):
- Misconception 1: [what user incorrectly believed]
- Misconception 2: [what user incorrectly believed]

Generate 5-10 new questions in SEF format focusing on these weak areas.
Use this exact format:
[Question text]
-* [Correct answer]
- [Incorrect option]
- [Incorrect option]

[Empty line between questions]
```

---

## Common SEF Use Cases for AI

### 1. Adaptive Learning Systems
- Parse Executed State to identify learning gaps
- Generate personalized follow-up questions
- Track progress over multiple SEF sessions

### 2. Question Generation
- Convert existing content to SEF format
- Create questions from text, documents, or curriculum
- Generate distractors (incorrect answers) for multiple choice

### 3. Performance Analytics
- Calculate scores and accuracy rates
- Identify patterns in user mistakes
- Generate learning recommendations

### 4. Content Conversion
- Convert from other formats (Aiken, VCE, etc.) to SEF
- Transform SEF to other assessment formats
- Migrate legacy quiz content

### 5. Educational Tools Integration
- Import SEF into learning management systems
- Create printable study materials from SEF
- Generate flashcards from SEF content

---

## Example AI Workflows

### Workflow 1: Question Generation from Text
```
Input: Text content or curriculum material
Process: 
1. Extract key concepts and facts
2. Generate questions covering main topics
3. Create plausible incorrect answers
4. Format in SEF Input State
Output: Ready-to-use SEF exam file
```

### Workflow 2: Performance-Based Follow-up
```
Input: SEF Executed State file
Process:
1. Parse user responses and identify errors
2. Analyze error patterns by topic
3. Generate targeted questions for weak areas
4. Create new SEF Input State for reinforcement
Output: Personalized follow-up exam
```

### Workflow 3: Format Conversion
```
Input: Questions in other formats (JSON, CSV, etc.)
Process:
1. Parse source format structure
2. Map to SEF question/answer structure
3. Apply SEF formatting rules
4. Validate output format
Output: Converted SEF format file
```

---

## Error Handling and Validation

When working with SEF files, validate:

1. **Format Compliance:**
   - Questions separated by blank lines
   - Answers start with `-` or `[]`
   - Correct answers marked with `-*`
   - Executed state has selection markers

2. **Content Quality:**
   - Each question has at least one correct answer
   - Questions are clear and unambiguous
   - Answer options are mutually exclusive
   - No duplicate answer options

3. **State Consistency:**
   - Executed state maintains correct answer markings from Input state
   - All answers have selection indicators in Executed state
   - Question count matches between states

---

## Advanced Features

### Multi-Language Support
SEF supports any language in question and answer text:
```
Hvad er hovedstaden i Danmark?
-* København
- Århus
- Odense
```

### Complex Question Types
```
Which of the following are advantages of SEF? (Select all that apply)
-* Plain text format
-* AI integration support
-* Cross-platform compatibility
- Requires proprietary software
-* Open source format
```

### Image Integration
```
Identify the geometric shape:
![Shape diagram](https://example.com/triangle.png)
-* Triangle
- Square
- Circle
- Pentagon
```

---

## Integration Best Practices

1. **File Handling:**
   - Use UTF-8 encoding for international character support
   - Maintain consistent line endings (prefer LF)
   - Keep backup copies of original Input State files

2. **User Experience:**
   - Present questions one at a time or in logical groups
   - Provide immediate feedback after completion
   - Show progress indicators for long exams

3. **Data Analysis:**
   - Store both Input and Executed states for comparison
   - Track timestamps for performance analytics
   - Maintain user progress histories for longitudinal analysis

4. **AI Integration:**
   - Use structured prompts for consistent output
   - Validate AI-generated content before use
   - Implement feedback loops for continuous improvement

---

## Conclusion

SEF provides a powerful, flexible format for AI-powered educational tools. Its simplicity enables easy parsing and generation, while its two-state design supports sophisticated learning analytics and adaptive question generation. When working with SEF, focus on maintaining format compliance while leveraging its structure for meaningful educational insights and personalized learning experiences.

For more examples and tools, explore the SEF repository structure including parsers, simulators, and AI integration examples.