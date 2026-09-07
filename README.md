🤖 AI Blog Writer for WordPress

Automatically generate complete WordPress blog posts using OpenAI — including articles, featured images, SEO metadata, categories, tags, content review, and scheduled publishing.

AI Blog Writer is a WordPress plugin built for automating content creation while still keeping your existing website and content structure in control.

✨ Features

* 🤖 AI-generated blog posts using OpenAI
* 🖼️ Automatic featured image generation
* 🔎 SEO meta titles and descriptions
* 🏷️ Automatic categories and tags
* 🧠 Uses your existing WordPress content as context
* ✅ AI review pass before saving the article
* 📝 Manual generation as drafts
* 🚀 Automatic publishing
* ⏰ Scheduled generation with WP-Cron
* ⚙️ Custom prompts and AI model settings

🧠 WordPress-aware content generation

Instead of generating articles without context, AI Blog Writer can use information already available on your WordPress website as editorial input.

This includes:

* Recent posts
* Pages
* Existing categories
* Existing tags
* Site title
* Site description

The AI can then generate content that better matches the subject and structure of your existing website.

📝 AI Blog Generation

Provide your own reusable prompt and let OpenAI generate a complete article.

The plugin can automatically determine relevant categories and tags from those that already exist in WordPress.

It does not create new categories or tags, helping keep your WordPress taxonomy clean.

Optional default categories and tags can also be configured as fallback terms.

🖼️ Featured Images

Each generated article can automatically receive an AI-generated featured image.

The image is created using a separate image prompt, allowing you to control the visual direction independently from the article prompt.

🔎 SEO Metadata

After the article has been generated, AI Blog Writer can create:

* SEO meta title
* SEO meta description

SEO metadata is generated from the completed article using dedicated prompts.

The plugin also supports syncing SEO metadata with:

* Yoast SEO
* Rank Math

✅ AI Review Pass

Before the generated content is saved, a second AI review can inspect the result.

The review process can identify and repair problems such as:

* Weak formatting
* Poor article structure
* Unclear sections
* Metadata issues
* Mismatched image direction

This provides an additional quality-control step between generation and publishing.

⏰ Automatic Publishing

AI Blog Writer supports both manual and automatic content generation.

Manual generation

Use the test button inside WordPress to generate a new article as a draft.

This makes it possible to inspect the complete result before publishing.

Scheduled generation

Configure an interval and let WordPress automatically generate and publish new articles using WP-Cron.

⚙️ Configuration

Go to:

WordPress → Settings → AI Blog Writer

You can configure:

* OpenAI API key
* Text model
* Image model
* Temperature
* Article prompt
* Image prompt
* SEO prompts
* Schedule interval
* Default category
* Default tag

📦 Installation

1. Download or clone the repository.
2. Copy the plugin folder to:
    wp-content/plugins/
3. Activate AI Blog Writer Drafts from the WordPress Plugins page.
4. Go to Settings → AI Blog Writer.
5. Enter your OpenAI API key.
6. Configure your prompts and models.
7. Save the settings.
8. Run a manual test.

📋 Requirements

* WordPress 6.4+
* PHP 8.0+
* OpenAI API key

Tested with WordPress up to 6.8.

🔑 OpenAI API

An OpenAI API key is required to generate content and images.

API usage may incur costs depending on the models you select and the amount of content generated.

Your API key and model configuration can be entered from the plugin settings page.

📁 Plugin Structure

ai-blog-writer/
├── ai-blog-writer.php
├── includes/
│   └── class-ai-blog-writer.php
└── readme.txt

🔍 Use Cases

AI Blog Writer can be useful for:

* AI-powered WordPress blogs
* Automated content marketing
* SEO content generation
* Niche websites
* Company blogs
* Ecommerce content
* Scheduled blog publishing
* Content automation workflows

🏷️ Keywords

WordPress AI AI Blog Writer OpenAI WordPress AI Content Generator Automatic Blogging WordPress Automation SEO Automation AI Article Generator OpenAI API AI Featured Images WP-Cron Content Marketing

📄 License

Licensed under the GPLv2 or later.

🚧 Version

0.1.0

Initial release.
