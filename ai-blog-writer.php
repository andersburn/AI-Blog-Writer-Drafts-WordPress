<?php
/**
 * Plugin Name: AI Blog Writer Drafts
 * Plugin URI: https://example.com/
 * Description: Generates WordPress draft blog posts and featured images with OpenAI, then validates the result before saving.
 * Version: 0.1.0
 * Author: Codex
 * License: GPL-2.0-or-later
 * Text Domain: ai-blog-writer
 */

if (! defined('ABSPATH')) {
	exit;
}

define('AIBW_PLUGIN_FILE', __FILE__);
define('AIBW_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AIBW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AIBW_PLUGIN_VERSION', '0.1.0');

require_once AIBW_PLUGIN_PATH . 'includes/class-ai-blog-writer.php';

register_activation_hook(AIBW_PLUGIN_FILE, array('AI_Blog_Writer', 'activate'));
register_deactivation_hook(AIBW_PLUGIN_FILE, array('AI_Blog_Writer', 'deactivate'));

AI_Blog_Writer::instance();
