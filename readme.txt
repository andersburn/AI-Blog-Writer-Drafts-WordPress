=== AI Blog Writer Drafts ===
Contributors: codex
Tags: ai, openai, blog, content, images
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create complete blog posts with OpenAI, generate a featured image, and run an automated review pass before the content is saved in WordPress.

== Description ==

AI Blog Writer Drafts is a WordPress plugin for site owners who want to automate content production without losing control.

Features:
- Generates a full blog post from a reusable prompt and the existing WordPress site context
- Uses recent posts, pages, categories, site title, and site description as editorial input
- Chooses only from existing WordPress categories and tags, with optional default fallback terms
- Creates a featured image for the post
- Generates SEO meta title and meta description from the finished article using dedicated prompts
- Runs a second review pass to check structure and repair weak output
- Publishes scheduled posts automatically while manual tests stay as drafts
- Includes a manual test button and an automated schedule via WP-Cron

== Installation ==

1. Copy this folder into `wp-content/plugins/`.
2. Activate `AI Blog Writer Drafts` in WordPress.
3. Go to `Settings > AI Blog Writer`.
4. Add your OpenAI API key and prompts.
5. Save the settings and test the generator.

== Frequently Asked Questions ==

= Does it publish posts automatically? =

Yes, scheduled runs publish posts automatically. The manual test button still creates a draft so you can review the result first.

= What OpenAI settings can I change? =

You can set the API key, text model, image model, temperature, text prompt, image prompt, and schedule interval in hours.

= Can it assign categories and tags automatically? =

Yes. The plugin can choose multiple existing categories and tags for each generated post. It will not create new categories or tags. You can also set one optional default category and one optional default tag that are always added as fallback terms.

= Can it write SEO meta title and description too? =

Yes. The plugin can generate both from the completed article using separate prompts and stores them in the post meta fields. It also syncs them to common SEO plugin fields for Yoast SEO and Rank Math.

= What does the review pass do? =

The plugin asks OpenAI to inspect the generated article and repair issues like weak formatting, unclear structure, mismatched image direction, or poor metadata before the draft is saved.

== Changelog ==

= 0.1.0 =
- Initial release
