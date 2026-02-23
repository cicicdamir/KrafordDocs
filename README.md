📂 KrafordDocs v10.5

    Professional Documentation with Native Markdown Support.

KrafordDocs is a lightweight, single-file PHP system for managing documentation and knowledge. Designed for speed, simplicity, and privacy, it utilizes a JSON file as its database. This makes it ideal for personal wikis, team documentation, or research notes without the need for a complex SQL database.
✨ Key Features

    📝 Markdown Support: Write documentation using simple Markdown syntax with a live preview.

    🎨 Dark/Light Mode: Automatic theme detection or manual toggle with preference saving.

    🔍 Smart Search: Fuzzy search powered by Fuse.js for rapid discovery of documents, tags, and categories.

    📜 Version History: Automatic backup of the last 5 versions for every document with full restoration (restore) support.

    🔗 Wiki Links: Interlink your documents easily using the [[Document Name]] syntax.

    📱 Responsive Design: Optimized for mobile, tablet, and desktop (mobile-first sidebar).

    ♿ Accessibility: Built to WCAG AA standards, featuring ARIA labels, keyboard navigation, and skip links.

    🔒 Security: Native CSRF protection, secure session management, and input validation.

    📥 Import/Export: Ability to backup and restore the entire database in JSON format.

    🖼️ Image Uploads: Drag & drop zone for embedding images directly into content via Base64.

    ⌨️ Keyboard Shortcuts: Fast access to core actions (Ctrl+S, Ctrl+K, etc.).

🚀 Installation

KrafordDocs is designed to function as a single-file application.

    Download: Save the source code as index.php.

    Upload: Place the file on your web server (Apache, Nginx, etc.).

    Permissions: Ensure the PHP process has write permissions in the directory where the script is located (to create kraford_docs.json and kraford_errors.log).

    Launch: Open your domain/path in a browser.

    Initialization: Upon the first launch, the system will automatically generate the welcome page and the database.

⚙️ Configuration

All configuration options are located at the top of the index.php file:

    $db_file: Path to the JSON database (default: kraford_docs.json).

    $log_file: Path to the error log file (default: kraford_errors.log).

    error_reporting: Error display level (default: E_ALL, display off for security).

📖 Usage Guide
Creating a Document

    Click + New Page in the top menu.

    Enter the title, category, description, and tags.

    Write your content in Markdown.

    Click Save (or press Ctrl + S).

Markdown & Extended Syntax
Element	Syntax
Heading	# Title, ## Subtitle
Bold	**text**
Italic	*text*
Code	`code`
Link	[text](url)
Image	![alt](url)
Wiki Link	[[Another Document]]
Table	`
Keyboard Shortcuts
Action	Shortcut
Search	Ctrl + K
Save	Ctrl + S
Preview	Ctrl + P
New Page	Ctrl + N
Close Modal	Esc
Help	Ctrl + H
🛠 Tech Stack

    Backend: PHP 7.4+

    Database: Structured JSON File

    Frontend: HTML5, CSS3 (Custom Properties), Vanilla JS

    Markdown Parser: Marked.js

    Search Engine: Fuse.js

    Syntax Highlighting: Highlight.js

    Fonts: Plus Jakarta Sans, JetBrains Mono

🐛 Resolved Issues (v10.5)

    ✅ Fixed Nested Forms Bug (Nested HTML forms corrected).

    ✅ Added Action Logging for easier debugging.

    ✅ Enhanced Input Validations during save operations.

    ✅ Improved CSRF Protection.

    ✅ Optimized Table Display with horizontal scroll indicators.

📂 File Structure

After initialization, your directory will contain:
Plaintext

├── index.php           # Core Application
├── kraford_docs.json   # Database (auto-generated)
└── kraford_errors.log  # Error Log (auto-generated)

🤝 Contribution

If you find a bug or have a suggestion for improvement, feel free to modify the code. Given the open-source nature of this single-file tool, customization is done directly within the code.
📄 License

This project is available under the MIT License. It is free for both personal and commercial use.
