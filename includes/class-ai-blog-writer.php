<?php
/**
 * Main plugin class.
 *
 * @package AI_Blog_Writer
 */

if (! defined('ABSPATH')) {
	exit;
}

class AI_Blog_Writer {
	const OPTION_KEY = 'aibw_settings';
	const LAST_RUN_OPTION = 'aibw_last_run';
	const NOTICE_TRANSIENT = 'aibw_admin_notice_';
	const CRON_HOOK = 'aibw_generate_post_event';
	const PROMPT_STORAGE_PREFIX = '__AIBW_PROMPT_B64__:';

	/**
	 * Singleton instance.
	 *
	 * @var AI_Blog_Writer|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return AI_Blog_Writer
	 */
	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		$plugin = self::instance();
		$plugin->schedule_cron_event(true);
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$plugin = self::instance();
		$plugin->clear_cron_event();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action('init', array($this, 'maybe_schedule_cron'));
		add_action('admin_menu', array($this, 'register_admin_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_post_aibw_run_test', array($this, 'handle_manual_run'));
		add_action('admin_notices', array($this, 'render_admin_notice'));
		add_action(self::CRON_HOOK, array($this, 'handle_scheduled_run'));
		add_action('update_option_' . self::OPTION_KEY, array($this, 'handle_settings_updated'), 10, 2);
		add_filter('cron_schedules', array($this, 'register_custom_schedule'));
	}

	/**
	 * Ensure the cron job exists while the plugin is active.
	 *
	 * @return void
	 */
	public function maybe_schedule_cron() {
		if (! wp_next_scheduled(self::CRON_HOOK)) {
			$this->schedule_cron_event(false);
		}
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'aibw_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array($this, 'sanitize_settings'),
				'default'           => $this->get_default_settings(),
			)
		);
	}

	/**
	 * Add settings page.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_options_page(
			__('AI Blog Writer', 'ai-blog-writer'),
			__('AI Blog Writer', 'ai-blog-writer'),
			'manage_options',
			'aibw',
			array($this, 'render_admin_page')
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if (! current_user_can('manage_options')) {
			return;
		}

		$settings      = $this->get_settings();
		$next_run      = wp_next_scheduled(self::CRON_HOOK);
		$last_run      = get_option(self::LAST_RUN_OPTION, array());
		$settings_link = admin_url('options-general.php?page=aibw');
		$history_posts = $this->get_generated_posts_history(20);
		$categories    = $this->get_taxonomy_term_options('category');
		$tags          = $this->get_taxonomy_term_options('post_tag');
		?>
		<div class="wrap">
			<h1><?php esc_html_e('AI Blog Writer', 'ai-blog-writer'); ?></h1>
			<p>
				<?php esc_html_e('This plugin writes complete blog posts, generates a featured image, and performs an automated review pass before saving the content in WordPress.', 'ai-blog-writer'); ?>
			</p>
			<p>
				<?php esc_html_e('How to use it:', 'ai-blog-writer'); ?>
				<?php esc_html_e('Add your OpenAI API key, choose a text model, adjust temperature and prompts, set how many hours should pass between runs, then save. The scheduled task publishes a new post automatically after the selected interval, and the test button lets you run the full flow immediately as a draft.', 'ai-blog-writer'); ?>
			</p>

			<table class="widefat striped" style="max-width: 960px; margin: 16px 0 24px;">
				<tbody>
					<tr>
						<td style="width: 220px;"><strong><?php esc_html_e('Current mode', 'ai-blog-writer'); ?></strong></td>
						<td><?php esc_html_e('Scheduled publishing', 'ai-blog-writer'); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e('Next scheduled run', 'ai-blog-writer'); ?></strong></td>
						<td>
							<?php
							echo $next_run
								? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run))
								: esc_html__('Not scheduled yet. Saving settings will schedule it.', 'ai-blog-writer');
							?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e('Settings page', 'ai-blog-writer'); ?></strong></td>
						<td><a href="<?php echo esc_url($settings_link); ?>"><?php echo esc_html($settings_link); ?></a></td>
					</tr>
				</tbody>
			</table>

			<?php if (! empty($last_run)) : ?>
				<div class="notice notice-info inline">
					<p>
						<strong><?php esc_html_e('Last run:', 'ai-blog-writer'); ?></strong>
						<?php echo esc_html($last_run['message']); ?>
							<?php if (! empty($last_run['context']['edit_url'])) : ?>
								<a href="<?php echo esc_url($last_run['context']['edit_url']); ?>"><?php esc_html_e('Open post', 'ai-blog-writer'); ?></a>
						<?php endif; ?>
						<?php if (! empty($last_run['timestamp'])) : ?>
							(<?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last_run['timestamp'])); ?>)
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php settings_fields('aibw_settings_group'); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="aibw_openai_api_key"><?php esc_html_e('OpenAI API key', 'ai-blog-writer'); ?></label>
							</th>
							<td>
								<input id="aibw_openai_api_key" name="<?php echo esc_attr(self::OPTION_KEY); ?>[openai_api_key]" type="password" class="regular-text" value="<?php echo esc_attr($settings['openai_api_key']); ?>" autocomplete="off" />
								<p class="description"><?php esc_html_e('Used for both the text generation and featured image generation requests.', 'ai-blog-writer'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="aibw_text_model"><?php esc_html_e('Text model', 'ai-blog-writer'); ?></label>
							</th>
							<td>
								<input id="aibw_text_model" name="<?php echo esc_attr(self::OPTION_KEY); ?>[text_model]" type="text" class="regular-text" value="<?php echo esc_attr($settings['text_model']); ?>" />
								<p class="description"><?php esc_html_e('Example: gpt-4.1 or gpt-5.1. Use any text-capable model that supports the Responses API.', 'ai-blog-writer'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="aibw_image_model"><?php esc_html_e('Image model', 'ai-blog-writer'); ?></label>
							</th>
							<td>
								<input id="aibw_image_model" name="<?php echo esc_attr(self::OPTION_KEY); ?>[image_model]" type="text" class="regular-text" value="<?php echo esc_attr($settings['image_model']); ?>" />
								<p class="description"><?php esc_html_e('Example: gpt-image-1.5 for featured image generation.', 'ai-blog-writer'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="aibw_temperature"><?php esc_html_e('Temperature', 'ai-blog-writer'); ?></label>
							</th>
							<td>
								<input id="aibw_temperature" name="<?php echo esc_attr(self::OPTION_KEY); ?>[temperature]" type="number" class="small-text" min="0" max="2" step="0.1" value="<?php echo esc_attr($settings['temperature']); ?>" />
								<p class="description"><?php esc_html_e('Controls how predictable or creative the text model should be.', 'ai-blog-writer'); ?></p>
							</td>
						</tr>
							<tr>
								<th scope="row">
									<label for="aibw_interval_hours"><?php esc_html_e('Run every X hours', 'ai-blog-writer'); ?></label>
								</th>
								<td>
									<input id="aibw_interval_hours" name="<?php echo esc_attr(self::OPTION_KEY); ?>[interval_hours]" type="number" class="small-text" min="1" max="720" step="1" value="<?php echo esc_attr($settings['interval_hours']); ?>" />
										<p class="description"><?php esc_html_e('The plugin will publish one new post after this many hours, then continue on that interval.', 'ai-blog-writer'); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="aibw_default_category_id"><?php esc_html_e('Default category', 'ai-blog-writer'); ?></label>
								</th>
								<td>
									<select id="aibw_default_category_id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_category_id]">
										<option value="0"><?php esc_html_e('None', 'ai-blog-writer'); ?></option>
										<?php foreach ($categories as $category) : ?>
											<option value="<?php echo esc_attr($category->term_id); ?>" <?php selected((int) $settings['default_category_id'], (int) $category->term_id); ?>>
												<?php echo esc_html($category->name); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e('Optional. This category is always added, even when the AI is unsure which categories fit.', 'ai-blog-writer'); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="aibw_default_tag_id"><?php esc_html_e('Default tag', 'ai-blog-writer'); ?></label>
								</th>
								<td>
									<select id="aibw_default_tag_id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_tag_id]">
										<option value="0"><?php esc_html_e('None', 'ai-blog-writer'); ?></option>
										<?php foreach ($tags as $tag) : ?>
											<option value="<?php echo esc_attr($tag->term_id); ?>" <?php selected((int) $settings['default_tag_id'], (int) $tag->term_id); ?>>
												<?php echo esc_html($tag->name); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e('Optional. This tag is always added, even when the AI leaves the tag list empty.', 'ai-blog-writer'); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e('Allow AI to update fields', 'ai-blog-writer'); ?></th>
								<td>
									<input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_title_updates]" value="0" />
									<label for="aibw_allow_title_updates" style="display:block; margin-bottom: 6px;">
										<input id="aibw_allow_title_updates" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_title_updates]" type="checkbox" value="1" <?php checked(! empty($settings['allow_title_updates'])); ?> />
										<?php esc_html_e('Title', 'ai-blog-writer'); ?>
									</label>

									<input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_tag_updates]" value="0" />
									<label for="aibw_allow_tag_updates" style="display:block; margin-bottom: 6px;">
										<input id="aibw_allow_tag_updates" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_tag_updates]" type="checkbox" value="1" <?php checked(! empty($settings['allow_tag_updates'])); ?> />
										<?php esc_html_e('Tags', 'ai-blog-writer'); ?>
									</label>

									<input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_category_updates]" value="0" />
									<label for="aibw_allow_category_updates" style="display:block; margin-bottom: 6px;">
										<input id="aibw_allow_category_updates" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_category_updates]" type="checkbox" value="1" <?php checked(! empty($settings['allow_category_updates'])); ?> />
										<?php esc_html_e('Category', 'ai-blog-writer'); ?>
									</label>

									<input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_image_updates]" value="0" />
									<label for="aibw_allow_image_updates" style="display:block; margin-bottom: 6px;">
										<input id="aibw_allow_image_updates" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_image_updates]" type="checkbox" value="1" <?php checked(! empty($settings['allow_image_updates'])); ?> />
										<?php esc_html_e('Featured image', 'ai-blog-writer'); ?>
									</label>
									<p class="description"><?php esc_html_e('Disable a field to prevent the AI review flow from changing it. Featured image generation is skipped when the image field is disabled.', 'ai-blog-writer'); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="aibw_text_prompt"><?php esc_html_e('Text prompt', 'ai-blog-writer'); ?></label>
								</th>
								<td>
								<textarea id="aibw_text_prompt" name="<?php echo esc_attr(self::OPTION_KEY); ?>[text_prompt]" rows="10" class="large-text code"><?php echo esc_textarea($settings['text_prompt']); ?></textarea>
								<p class="description"><?php esc_html_e('General instructions for how the draft article should be written. Site information and recent post context are added automatically.', 'ai-blog-writer'); ?></p>
							</td>
						</tr>
							<tr>
								<th scope="row">
									<label for="aibw_image_prompt"><?php esc_html_e('Image prompt', 'ai-blog-writer'); ?></label>
								</th>
								<td>
									<textarea id="aibw_image_prompt" name="<?php echo esc_attr(self::OPTION_KEY); ?>[image_prompt]" rows="8" class="large-text code"><?php echo esc_textarea($settings['image_prompt']); ?></textarea>
									<p class="description"><?php esc_html_e('General instructions for the featured image. The final prompt also includes the article topic and visual focus.', 'ai-blog-writer'); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="aibw_meta_title_prompt"><?php esc_html_e('Meta title prompt', 'ai-blog-writer'); ?></label>
								</th>
								<td>
									<textarea id="aibw_meta_title_prompt" name="<?php echo esc_attr(self::OPTION_KEY); ?>[meta_title_prompt]" rows="5" class="large-text code"><?php echo esc_textarea($settings['meta_title_prompt']); ?></textarea>
									<p class="description"><?php esc_html_e('Instructions for generating the SEO meta title. The completed article data is added automatically.', 'ai-blog-writer'); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="aibw_meta_description_prompt"><?php esc_html_e('Meta description prompt', 'ai-blog-writer'); ?></label>
								</th>
								<td>
									<textarea id="aibw_meta_description_prompt" name="<?php echo esc_attr(self::OPTION_KEY); ?>[meta_description_prompt]" rows="5" class="large-text code"><?php echo esc_textarea($settings['meta_description_prompt']); ?></textarea>
									<p class="description"><?php esc_html_e('Instructions for generating the SEO meta description. The completed article data is added automatically.', 'ai-blog-writer'); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
				<?php submit_button(__('Save settings', 'ai-blog-writer')); ?>
			</form>

			<hr />

			<h2><?php esc_html_e('Test the plugin', 'ai-blog-writer'); ?></h2>
			<p><?php esc_html_e('This button runs the full generation flow immediately and creates a new draft if everything succeeds.', 'ai-blog-writer'); ?></p>
			<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
				<?php wp_nonce_field('aibw_run_test'); ?>
				<input type="hidden" name="action" value="aibw_run_test" />
				<?php submit_button(__('Generate test draft now', 'ai-blog-writer'), 'secondary', 'submit', false); ?>
			</form>

			<hr />

			<h2><?php esc_html_e('Generated post history', 'ai-blog-writer'); ?></h2>
			<p><?php esc_html_e('Here is a list of the latest blog posts created by the plugin and when they were generated.', 'ai-blog-writer'); ?></p>

			<?php if (empty($history_posts)) : ?>
				<p><?php esc_html_e('No posts have been generated by the plugin yet.', 'ai-blog-writer'); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 1100px;">
					<thead>
						<tr>
							<th><?php esc_html_e('Title', 'ai-blog-writer'); ?></th>
							<th><?php esc_html_e('Generated', 'ai-blog-writer'); ?></th>
							<th><?php esc_html_e('Status', 'ai-blog-writer'); ?></th>
							<th><?php esc_html_e('Source', 'ai-blog-writer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($history_posts as $history_post) : ?>
							<?php
							$generated_at = get_post_meta($history_post->ID, '_aibw_generated', true);
							$run_mode     = get_post_meta($history_post->ID, '_aibw_generation_mode', true);
							$status_obj   = get_post_status_object($history_post->post_status);
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url(get_edit_post_link($history_post->ID)); ?>">
										<?php echo esc_html(get_the_title($history_post)); ?>
									</a>
								</td>
								<td><?php echo esc_html($this->format_generated_datetime($generated_at)); ?></td>
								<td><?php echo esc_html($status_obj ? $status_obj->label : ucfirst((string) $history_post->post_status)); ?></td>
								<td><?php echo esc_html($this->format_generation_mode($run_mode)); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			</div>
			<?php $this->render_prompt_submit_script(); ?>
			<?php
		}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public function sanitize_settings($input) {
		$defaults = $this->get_default_settings();
		$input    = is_array($input) ? $input : array();

		$settings = array(
			'openai_api_key'          => isset($input['openai_api_key']) ? sanitize_text_field($input['openai_api_key']) : $defaults['openai_api_key'],
			'text_model'              => isset($input['text_model']) ? sanitize_text_field($input['text_model']) : $defaults['text_model'],
			'image_model'             => isset($input['image_model']) ? sanitize_text_field($input['image_model']) : $defaults['image_model'],
			'temperature'             => isset($input['temperature']) ? (string) min(2, max(0, (float) $input['temperature'])) : $defaults['temperature'],
			'interval_hours'          => isset($input['interval_hours']) ? (string) min(720, max(1, (int) $input['interval_hours'])) : $defaults['interval_hours'],
			'default_category_id'     => isset($input['default_category_id']) ? $this->sanitize_term_setting($input['default_category_id'], 'category') : $defaults['default_category_id'],
			'default_tag_id'          => isset($input['default_tag_id']) ? $this->sanitize_term_setting($input['default_tag_id'], 'post_tag') : $defaults['default_tag_id'],
			'allow_title_updates'     => isset($input['allow_title_updates']) ? $this->sanitize_toggle_setting($input['allow_title_updates']) : $defaults['allow_title_updates'],
			'allow_tag_updates'       => isset($input['allow_tag_updates']) ? $this->sanitize_toggle_setting($input['allow_tag_updates']) : $defaults['allow_tag_updates'],
			'allow_category_updates'  => isset($input['allow_category_updates']) ? $this->sanitize_toggle_setting($input['allow_category_updates']) : $defaults['allow_category_updates'],
			'allow_image_updates'     => isset($input['allow_image_updates']) ? $this->sanitize_toggle_setting($input['allow_image_updates']) : $defaults['allow_image_updates'],
			'text_prompt'             => isset($input['text_prompt']) ? $this->sanitize_prompt_setting($input['text_prompt']) : $defaults['text_prompt'],
			'image_prompt'            => isset($input['image_prompt']) ? sanitize_textarea_field($input['image_prompt']) : $defaults['image_prompt'],
			'meta_title_prompt'       => isset($input['meta_title_prompt']) ? sanitize_textarea_field($input['meta_title_prompt']) : $defaults['meta_title_prompt'],
			'meta_description_prompt' => isset($input['meta_description_prompt']) ? sanitize_textarea_field($input['meta_description_prompt']) : $defaults['meta_description_prompt'],
		);

		return wp_parse_args($settings, $defaults);
	}

	/**
	 * Return plugin settings merged with defaults.
	 *
	 * @return array
	 */
	private function get_settings() {
		$settings = wp_parse_args((array) get_option(self::OPTION_KEY, array()), $this->get_default_settings());

		$settings['text_prompt'] = isset($settings['text_prompt'])
			? $this->decode_prompt_setting($settings['text_prompt'])
			: '';
		$settings['allow_title_updates'] = $this->sanitize_toggle_setting(isset($settings['allow_title_updates']) ? $settings['allow_title_updates'] : 1);
		$settings['allow_tag_updates'] = $this->sanitize_toggle_setting(isset($settings['allow_tag_updates']) ? $settings['allow_tag_updates'] : 1);
		$settings['allow_category_updates'] = $this->sanitize_toggle_setting(isset($settings['allow_category_updates']) ? $settings['allow_category_updates'] : 1);
		$settings['allow_image_updates'] = $this->sanitize_toggle_setting(isset($settings['allow_image_updates']) ? $settings['allow_image_updates'] : 1);

		return $settings;
	}

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	private function get_default_settings() {
		return array(
			'openai_api_key' => '',
			'text_model'     => 'gpt-4.1',
			'image_model'    => 'gpt-image-1.5',
			'temperature'    => '0.7',
			'interval_hours' => '24',
			'default_category_id' => 0,
			'default_tag_id' => 0,
			'allow_title_updates' => 1,
			'allow_tag_updates' => 1,
			'allow_category_updates' => 1,
			'allow_image_updates' => 1,
			'text_prompt'    => "Write a complete, publish-ready draft blog post in the site's primary language. Use the site context to pick a relevant topic, avoid repeating recent articles, and make the article genuinely useful. Return HTML only in the content field. Include a strong introduction, meaningful H2 subheadings, helpful examples when relevant, and a clear ending.",
			'image_prompt'   => "Create a high-quality featured image for the article. Match the topic, audience, and site context. Use a clean editorial style, no watermarks, and avoid text overlays unless absolutely necessary.",
			'meta_title_prompt' => "Write an SEO meta title based on the completed article. Make it clear, compelling, aligned with search intent, and keep it under 60 characters.",
			'meta_description_prompt' => "Write an SEO meta description based on the completed article. Make it accurate, enticing, and click-worthy while keeping it under 155 characters.",
		);
	}

	/**
	 * Schedule custom WP-Cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_custom_schedule($schedules) {
		$settings                      = $this->get_settings();
		$interval_hours                = max(1, (int) $settings['interval_hours']);
		$schedules['aibw_custom_rate'] = array(
			'interval' => $interval_hours * HOUR_IN_SECONDS,
			'display'  => sprintf(
				/* translators: %d: number of hours between runs. */
				__('Every %d hours', 'ai-blog-writer'),
				$interval_hours
			),
		);

		return $schedules;
	}

	/**
	 * React to settings updates.
	 *
	 * @param array $old_value Previous settings.
	 * @param array $value New settings.
	 * @return void
	 */
	public function handle_settings_updated($old_value, $value) {
		$old_interval = isset($old_value['interval_hours']) ? (int) $old_value['interval_hours'] : 0;
		$new_interval = isset($value['interval_hours']) ? (int) $value['interval_hours'] : 0;

		if ($old_interval !== $new_interval || ! wp_next_scheduled(self::CRON_HOOK)) {
			$this->schedule_cron_event(true);
		}
	}

	/**
	 * Schedule the generation event.
	 *
	 * @param bool $reset Whether to clear the old event first.
	 * @return void
	 */
	private function schedule_cron_event($reset = false) {
		$settings = $this->get_settings();
		$interval = max(1, (int) $settings['interval_hours']) * HOUR_IN_SECONDS;

		if ($reset) {
			$this->clear_cron_event();
		}

		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + $interval, 'aibw_custom_rate', self::CRON_HOOK);
		}
	}

	/**
	 * Clear scheduled event.
	 *
	 * @return void
	 */
	private function clear_cron_event() {
		$timestamp = wp_next_scheduled(self::CRON_HOOK);

		while ($timestamp) {
			wp_unschedule_event($timestamp, self::CRON_HOOK);
			$timestamp = wp_next_scheduled(self::CRON_HOOK);
		}
	}

	/**
	 * Execute scheduled generation.
	 *
	 * @return void
	 */
	public function handle_scheduled_run() {
		$result = $this->generate_and_store_draft('scheduled');

		if (is_wp_error($result)) {
			$this->log_last_run('error', $result->get_error_message());
			return;
		}

		$message = sprintf(
			/* translators: %d: Post ID. */
			__('Scheduled run completed. Post #%d was published.', 'ai-blog-writer'),
			(int) $result['post_id']
		);

		$this->log_last_run('success', $message, $result);
	}

	/**
	 * Execute manual generation from admin.
	 *
	 * @return void
	 */
	public function handle_manual_run() {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do this.', 'ai-blog-writer'));
		}

		check_admin_referer('aibw_run_test');

		$result = $this->generate_and_store_draft('manual');

		if (is_wp_error($result)) {
			$this->set_admin_notice('error', $result->get_error_message());
			$this->log_last_run('error', $result->get_error_message());
		} else {
			$message = sprintf(
				/* translators: 1: Post title, 2: Post ID. */
				__('Test run completed. Draft "%1$s" (#%2$d) was created.', 'ai-blog-writer'),
				$result['title'],
				(int) $result['post_id']
			);

			$this->set_admin_notice(
				'success',
				$message,
				array(
					'edit_url'   => $result['edit_url'],
					'link_label' => __('Open draft', 'ai-blog-writer'),
				)
			);
			$this->log_last_run('success', $message, $result);
		}

		wp_safe_redirect(admin_url('options-general.php?page=aibw'));
		exit;
	}

	/**
	 * Render one-time admin notice.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		if (! current_user_can('manage_options')) {
			return;
		}

		$notice = get_transient(self::NOTICE_TRANSIENT . get_current_user_id());

		if (empty($notice) || empty($notice['message'])) {
			return;
		}

		delete_transient(self::NOTICE_TRANSIENT . get_current_user_id());
		$class = ! empty($notice['type']) && 'error' === $notice['type'] ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
		?>
		<div class="<?php echo esc_attr($class); ?>">
			<p>
				<?php echo esc_html($notice['message']); ?>
				<?php if (! empty($notice['edit_url'])) : ?>
					<a href="<?php echo esc_url($notice['edit_url']); ?>"><?php echo esc_html(! empty($notice['link_label']) ? $notice['link_label'] : __('Open draft', 'ai-blog-writer')); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save an admin notice for next request.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 * @param array  $context Optional notice context.
	 * @return void
	 */
	private function set_admin_notice($type, $message, $context = array()) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'       => $type,
				'message'    => wp_strip_all_tags($message),
				'edit_url'   => ! empty($context['edit_url']) ? esc_url_raw($context['edit_url']) : '',
				'link_label' => ! empty($context['link_label']) ? sanitize_text_field($context['link_label']) : '',
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Persist last run information.
	 *
	 * @param string $status Status label.
	 * @param string $message Human readable message.
	 * @param array  $context Optional context data.
	 * @return void
	 */
	private function log_last_run($status, $message, $context = array()) {
		update_option(
			self::LAST_RUN_OPTION,
			array(
				'status'    => sanitize_key($status),
				'message'   => wp_strip_all_tags($message),
				'timestamp' => time(),
				'context'   => $context,
			),
			false
		);
	}

	/**
	 * Run the full generation workflow.
	 *
	 * @param string $mode scheduled|manual.
	 * @return array|\WP_Error
	 */
	private function generate_and_store_draft($mode) {
		$settings = $this->get_settings();

		if (empty($settings['openai_api_key'])) {
			return new WP_Error('aibw_missing_api_key', __('The OpenAI API key is missing. Save it in the plugin settings before running the generator.', 'ai-blog-writer'));
		}

		$site_context = $this->build_site_context();
		$draft        = $this->generate_draft_payload($settings, $site_context, $mode);
		$locked_title = '';
		$locked_slug  = '';

		if (is_wp_error($draft)) {
			return $draft;
		}

		if (empty($settings['allow_title_updates'])) {
			$locked_title = isset($draft['title']) ? sanitize_text_field($draft['title']) : '';
			$locked_slug  = isset($draft['slug']) ? sanitize_title($draft['slug']) : sanitize_title($locked_title);
		}

		$validated = $this->validate_draft_payload($settings, $site_context, $draft);

		if (is_wp_error($validated)) {
			return $validated;
		}

		$validated  = $this->enforce_locked_draft_fields($draft, $validated, $settings);
		if ('' !== $locked_title) {
			$validated['title'] = $locked_title;
		}
		if ('' !== $locked_slug) {
			$validated['slug'] = $locked_slug;
		}

		$normalized = $this->normalize_draft_payload($validated, $site_context);
		$normalized = $this->apply_draft_field_permissions($normalized, $settings);
		if ('' !== $locked_title) {
			$normalized['title'] = $locked_title;
		}
		if ('' !== $locked_slug) {
			$normalized['slug'] = $locked_slug;
		}

		$seo_meta = $this->generate_seo_metadata($settings, $normalized, $site_context);

		if (is_wp_error($seo_meta)) {
			return $seo_meta;
		}

		$normalized = array_merge($normalized, $this->normalize_seo_metadata($seo_meta, $normalized, $settings));
		if ('' !== $locked_title) {
			$normalized['title'] = $locked_title;
		}
		if ('' !== $locked_slug) {
			$normalized['slug'] = $locked_slug;
		}

		$image      = array(
			'attachment_id' => 0,
			'file'          => '',
			'url'           => '',
			'alt'           => '',
		);

		if (! empty($settings['allow_image_updates'])) {
			$image = $this->generate_featured_image($settings, $normalized);

			if (is_wp_error($image)) {
				return $image;
			}
		}

		$post_id = $this->create_wordpress_draft($normalized, $image, $mode);

		if (is_wp_error($post_id)) {
			return $post_id;
		}

		$post_check = $this->verify_wordpress_post($post_id, $normalized, $image);

		if (is_wp_error($post_check)) {
			return $post_check;
		}

		return array(
			'post_id'   => $post_id,
			'edit_url'  => get_edit_post_link($post_id, 'raw'),
			'title'     => $normalized['title'],
			'image_id'  => $image['attachment_id'],
			'review'    => $normalized['review_notes'],
			'category'  => $normalized['category_name'],
			'meta_title' => $normalized['meta_title'],
			'meta_description' => $normalized['meta_description'],
			'generated' => get_post_meta($post_id, '_aibw_generated', true),
			'mode'      => sanitize_key($mode),
		);
	}

	/**
	 * Build site context from WordPress data.
	 *
	 * @return array
	 */
	private function build_site_context() {
		$categories   = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$recent_posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$recent_pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'orderby'        => 'date',
				'order'          => 'DESC',
				)
			);
		$tags         = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		return array(
			'site_name'      => get_bloginfo('name'),
			'site_tagline'   => get_bloginfo('description'),
			'site_url'       => home_url('/'),
			'language'       => get_bloginfo('language'),
			'timezone'       => wp_timezone_string() ? wp_timezone_string() : 'UTC',
			'generated_at'   => current_time('mysql'),
			'categories'     => array_values(
				array_filter(
					array_map(
						static function ($category) {
							if (! $category instanceof WP_Term) {
								return null;
							}

								return array(
									'id'   => (int) $category->term_id,
									'name' => $category->name,
									'slug' => $category->slug,
								);
							},
							$categories
						)
					)
				),
			'tags'           => array_values(
				array_filter(
					array_map(
						static function ($tag) {
							if (! $tag instanceof WP_Term) {
								return null;
							}

							return array(
								'id'   => (int) $tag->term_id,
								'name' => $tag->name,
								'slug' => $tag->slug,
							);
						},
						is_array($tags) ? $tags : array()
					)
				)
			),
			'recent_posts'   => array_values(
				array_map(
					static function ($post) {
						return array(
							'title'   => get_the_title($post),
							'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_excerpt ? $post->post_excerpt : $post->post_content), 30, '...'),
							'url'     => get_permalink($post),
						);
					},
					$recent_posts
				)
			),
			'recent_pages'   => array_values(
				array_map(
					static function ($post) {
						return array(
							'title'   => get_the_title($post),
							'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_content), 24, '...'),
							'url'     => get_permalink($post),
						);
					},
					$recent_pages
				)
			),
			'default_author' => wp_get_current_user() instanceof WP_User ? wp_get_current_user()->display_name : '',
		);
	}

	/**
	 * Generate first-pass draft content.
	 *
	 * @param array  $settings Plugin settings.
	 * @param array  $site_context Site context.
	 * @param string $mode Run mode.
	 * @return array|\WP_Error
	 */
	private function generate_draft_payload($settings, $site_context, $mode) {
		$target_status = 'scheduled' === $mode ? 'publish' : 'draft';

		$input = array(
			'run_mode' => $mode,
			'goal'     => 'Create one complete blog post that fits the site.',
			'prompt'   => $settings['text_prompt'],
			'context'  => $site_context,
			'format'   => array(
				'status'      => $target_status,
				'content'     => 'HTML only',
				'avoid'       => array('Markdown', 'Repeated recent topics', 'Placeholder text', 'Filler introductions'),
				'requirements' => array(
					'Create a specific, original topic that suits the site.',
					'Make the content useful and publication-ready.',
					'Include an intro, multiple H2 sections, and a concise ending.',
					'Choose zero to three category_slugs only from the provided existing categories list.',
					'Choose zero to six tag_slugs only from the provided existing tags list.',
					'If you are unsure about taxonomy, return an empty array instead of inventing categories or tags.',
				),
			),
		);

		$response = $this->request_structured_text(
			$settings,
			$this->get_generation_schema(),
			"Create a full WordPress article. Follow the schema exactly. Use HTML in content_html and never markdown.",
			wp_json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			4500
		);

		if (is_wp_error($response)) {
			return $response;
		}

		return $response;
	}

	/**
	 * Review and repair generated content.
	 *
	 * @param array $settings Plugin settings.
	 * @param array $site_context Site context.
	 * @param array $draft Raw generated draft.
	 * @return array|\WP_Error
	 */
	private function validate_draft_payload($settings, $site_context, $draft) {
		$checklist = array(
			'Slug is clean and concise.',
			'Content is valid HTML and includes H2 sections.',
		);
		$locked_fields = array();

		if (! empty($settings['allow_title_updates'])) {
			$checklist[] = 'Title is clear and specific.';
		} else {
			$checklist[] = 'Title must remain exactly as in original_draft.';
			$locked_fields[] = 'title';
		}
		$checklist[] = 'Excerpt is readable and not too long.';

		if (! empty($settings['allow_category_updates'])) {
			$checklist[] = 'category_slugs only use existing categories from the provided context.';
			$checklist[] = 'If category taxonomy is uncertain, category_slugs should stay empty.';
		} else {
			$checklist[] = 'category_slugs must remain exactly as in original_draft.';
			$locked_fields[] = 'category_slugs';
		}

		if (! empty($settings['allow_tag_updates'])) {
			$checklist[] = 'tag_slugs only use existing tags from the provided context.';
			$checklist[] = 'If tag taxonomy is uncertain, tag_slugs should stay empty.';
		} else {
			$checklist[] = 'tag_slugs must remain exactly as in original_draft.';
			$locked_fields[] = 'tag_slugs';
		}

		if (! empty($settings['allow_image_updates'])) {
			$checklist[] = 'Featured image prompt and alt text match the article.';
		} else {
			$checklist[] = 'image_focus and image_alt must remain exactly as in original_draft.';
			$locked_fields[] = 'image_focus';
			$locked_fields[] = 'image_alt';
		}

		$input = array(
			'goal'          => 'Review the generated WordPress article and repair every issue before publication.',
			'original_draft'=> $draft,
			'prompt'        => $settings['text_prompt'],
			'context'       => $site_context,
			'checklist'     => $checklist,
		);
		$instructions = "Review the article critically, fix any weaknesses, and return only the repaired version that matches the schema.";

		if (! empty($locked_fields)) {
			$input['locked_fields'] = array_values(array_unique($locked_fields));
			$instructions .= ' Locked fields must stay unchanged.';
		}

		$response = $this->request_structured_text(
			$settings,
			$this->get_validation_schema(),
			$instructions,
			wp_json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			5000
		);

		if (is_wp_error($response)) {
			return $response;
		}

		return $response;
	}

	/**
	 * Generate SEO metadata from the completed article.
	 *
	 * @param array $settings Plugin settings.
	 * @param array $draft Normalized draft payload.
	 * @param array $site_context Site context.
	 * @return array|\WP_Error
	 */
	private function generate_seo_metadata($settings, $draft, $site_context) {
		$article_text = wp_strip_all_tags($draft['content_html']);

		$input = array(
			'goal' => 'Generate SEO meta title and meta description for the completed blog post.',
			'meta_title_prompt' => $settings['meta_title_prompt'],
			'meta_description_prompt' => $settings['meta_description_prompt'],
			'article' => array(
				'title' => $draft['title'],
				'excerpt' => $draft['excerpt'],
				'categories' => $draft['category_names'],
				'tags' => $draft['tag_names'],
				'content_text' => $article_text,
			),
			'context' => array(
				'site_name' => $site_context['site_name'],
				'site_tagline' => $site_context['site_tagline'],
				'language' => $site_context['language'],
			),
			'requirements' => array(
				'Meta title must stay within 60 characters.',
				'Meta description must stay within 155 characters.',
				'Both fields must match the actual article and search intent.',
			),
		);

		return $this->request_structured_text(
			$settings,
			$this->get_seo_metadata_schema(),
			"Generate SEO metadata from the completed article. Follow the two prompts exactly and return only the structured result.",
			wp_json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			800
		);
	}

	/**
	 * Perform a structured Responses API request.
	 *
	 * @param array  $settings Plugin settings.
	 * @param array  $schema JSON schema.
	 * @param string $instructions System instructions.
	 * @param string $input User input payload.
	 * @param int    $max_output_tokens Max output tokens.
	 * @return array|\WP_Error
	 */
	private function request_structured_text($settings, $schema, $instructions, $input, $max_output_tokens) {
		$payload = array(
			'model'             => $settings['text_model'],
			'temperature'       => (float) $settings['temperature'],
			'instructions'      => $instructions,
			'input'             => $input,
			'max_output_tokens' => $max_output_tokens,
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => isset($schema['name']) ? $schema['name'] : 'article_payload',
					'strict' => true,
					'schema' => $schema['schema'],
				),
			),
		);

		$response = $this->perform_openai_request('https://api.openai.com/v1/responses', $payload, $settings['openai_api_key'], 120);

		if (is_wp_error($response)) {
			return $response;
		}

		$raw_text = $this->extract_text_from_response($response);

		if (empty($raw_text)) {
			return new WP_Error('aibw_empty_response', __('OpenAI returned an empty response for the text generation request.', 'ai-blog-writer'));
		}

		$decoded = $this->decode_json_payload($raw_text);

		if (! is_array($decoded)) {
			return new WP_Error('aibw_invalid_json', __('The structured response from OpenAI could not be decoded as JSON.', 'ai-blog-writer'));
		}

		return $decoded;
	}

	/**
	 * Generate featured image and save it as attachment.
	 *
	 * @param array $settings Plugin settings.
	 * @param array $draft Validated draft payload.
	 * @return array|\WP_Error
	 */
	private function generate_featured_image($settings, $draft) {
		$prompt = trim(
			$settings['image_prompt'] . "\n\n" .
			'Article title: ' . $draft['title'] . "\n" .
			'Article excerpt: ' . $draft['excerpt'] . "\n" .
			'Visual focus: ' . $draft['image_focus'] . "\n" .
			'Accessibility alt text goal: ' . $draft['image_alt']
		);

		$payload = array(
			'model'   => $settings['image_model'],
			'prompt'  => $prompt,
			'size'    => '1024x1024',
			'quality' => 'medium',
		);

		$response = $this->perform_openai_request('https://api.openai.com/v1/images/generations', $payload, $settings['openai_api_key'], 180);

		if (is_wp_error($response)) {
			return $response;
		}

		if (empty($response['data'][0]['b64_json'])) {
			return new WP_Error('aibw_missing_image', __('The image generation response did not contain image data.', 'ai-blog-writer'));
		}

		$binary = base64_decode($response['data'][0]['b64_json'], true);

		if (false === $binary) {
			return new WP_Error('aibw_invalid_image', __('The featured image could not be decoded.', 'ai-blog-writer'));
		}

		return $this->save_generated_image($binary, $draft);
	}

	/**
	 * Save generated image in Media Library.
	 *
	 * @param string $binary Binary image data.
	 * @param array  $draft Draft payload.
	 * @return array|\WP_Error
	 */
	private function save_generated_image($binary, $draft) {
		$filename = sanitize_file_name($draft['slug'] . '-featured.png');
		$upload   = wp_upload_bits($filename, null, $binary);

		if (! empty($upload['error'])) {
			return new WP_Error('aibw_upload_error', $upload['error']);
		}

		$filetype = wp_check_filetype($upload['file'], null);

		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => $draft['title'] . ' featured image',
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment($attachment, $upload['file']);

		if (is_wp_error($attachment_id)) {
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
		wp_update_attachment_metadata($attachment_id, $metadata);
		update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($draft['image_alt']));

		return array(
			'attachment_id' => $attachment_id,
			'file'          => $upload['file'],
			'url'           => $upload['url'],
			'alt'           => $draft['image_alt'],
		);
	}

	/**
	 * Insert draft post into WordPress.
	 *
	 * @param array  $draft Validated draft payload.
	 * @param array  $image Image data.
	 * @param string $mode Run mode.
	 * @return int|\WP_Error
	 */
	private function create_wordpress_draft($draft, $image, $mode) {
		$generated_at = current_time('mysql');
		$post_status  = 'scheduled' === $mode ? 'publish' : 'draft';

		$postarr = array(
			'post_title'   => $draft['title'],
			'post_name'    => $draft['slug'],
			'post_content' => $draft['content_html'],
			'post_excerpt' => $draft['excerpt'],
			'post_status'  => $post_status,
			'post_type'    => 'post',
		);

		$post_id = wp_insert_post($postarr, true);

		if (is_wp_error($post_id)) {
			return $post_id;
		}

		if (! empty($image['attachment_id'])) {
			set_post_thumbnail($post_id, (int) $image['attachment_id']);
		}

		$this->assign_terms_to_post($post_id, $draft);

		update_post_meta($post_id, '_aibw_review_notes', sanitize_textarea_field($draft['review_notes']));
		update_post_meta($post_id, '_aibw_image_prompt_focus', sanitize_textarea_field($draft['image_focus']));
		update_post_meta($post_id, '_aibw_generated', $generated_at);
		update_post_meta($post_id, '_aibw_generated_timestamp', (string) current_time('timestamp'));
		update_post_meta($post_id, '_aibw_generation_mode', sanitize_key($mode));
		update_post_meta($post_id, '_aibw_meta_title', sanitize_text_field($draft['meta_title']));
		update_post_meta($post_id, '_aibw_meta_description', sanitize_textarea_field($draft['meta_description']));
		update_post_meta($post_id, '_aibw_category_slugs', $draft['category_slugs']);
		update_post_meta($post_id, '_aibw_tag_slugs', $draft['tag_slugs']);
		$this->sync_seo_plugin_meta($post_id, $draft['meta_title'], $draft['meta_description']);

		return $post_id;
	}

	/**
	 * Get recent posts created by this plugin.
	 *
	 * @param int $limit Number of posts to return.
	 * @return WP_Post[]
	 */
	private function get_generated_posts_history($limit = 20) {
		$limit = max(1, (int) $limit);

		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array('draft', 'publish', 'pending', 'future', 'private'),
				'posts_per_page' => $limit,
				'meta_key'       => '_aibw_generated',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Format stored generation datetime.
	 *
	 * @param string $generated_at Stored datetime string.
	 * @return string
	 */
	private function format_generated_datetime($generated_at) {
		$generated_at = trim((string) $generated_at);

		if ('' === $generated_at) {
			return __('Unknown time', 'ai-blog-writer');
		}

		$datetime = date_create_immutable_from_format('Y-m-d H:i:s', $generated_at, wp_timezone());

		if (! $datetime) {
			return $generated_at;
		}

		return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $datetime->getTimestamp(), wp_timezone());
	}

	/**
	 * Format generation mode for the admin UI.
	 *
	 * @param string $mode Stored generation mode.
	 * @return string
	 */
	private function format_generation_mode($mode) {
		if ('manual' === $mode) {
			return __('Manual test', 'ai-blog-writer');
		}

		if ('scheduled' === $mode) {
			return __('Scheduled run', 'ai-blog-writer');
		}

		return __('Unknown', 'ai-blog-writer');
	}

	/**
	 * Final WordPress-level verification.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $draft Draft payload.
	 * @param array $image Image data.
	 * @return true|\WP_Error
	 */
	private function verify_wordpress_post($post_id, $draft, $image) {
		$post = get_post($post_id);

		if (! $post instanceof WP_Post) {
			return new WP_Error('aibw_missing_post', __('The generated draft could not be loaded after creation.', 'ai-blog-writer'));
		}

		$updates = array('ID' => $post_id);

		if (empty($post->post_title)) {
			$updates['post_title'] = $draft['title'];
		}

		if (empty($post->post_name)) {
			$updates['post_name'] = $draft['slug'];
		}

		if (empty($post->post_excerpt)) {
			$updates['post_excerpt'] = $draft['excerpt'];
		}

		if (empty($post->post_content)) {
			$updates['post_content'] = $draft['content_html'];
		}

		if (count($updates) > 1) {
			$result = wp_update_post($updates, true);

			if (is_wp_error($result)) {
				return $result;
			}
		}

		if (! has_post_thumbnail($post_id) && ! empty($image['attachment_id'])) {
			set_post_thumbnail($post_id, (int) $image['attachment_id']);
		}

		$this->assign_terms_to_post($post_id, $draft);

		if (empty(get_post_meta($post_id, '_aibw_meta_title', true))) {
			update_post_meta($post_id, '_aibw_meta_title', sanitize_text_field($draft['meta_title']));
		}

		if (empty(get_post_meta($post_id, '_aibw_meta_description', true))) {
			update_post_meta($post_id, '_aibw_meta_description', sanitize_textarea_field($draft['meta_description']));
		}

		$this->sync_seo_plugin_meta($post_id, $draft['meta_title'], $draft['meta_description']);

		return true;
	}

	/**
	 * Normalize generated payload with local safeguards.
	 *
	 * @param array $draft Generated payload.
	 * @param array $site_context Site context.
	 * @return array
	 */
	private function normalize_draft_payload($draft, $site_context) {
		$title        = isset($draft['title']) ? sanitize_text_field($draft['title']) : '';
		$content_html = isset($draft['content_html']) ? (string) $draft['content_html'] : '';
		$content_html = $this->normalize_html_content($content_html, $title);
		$excerpt      = isset($draft['excerpt']) ? sanitize_textarea_field($draft['excerpt']) : '';
		$excerpt      = $this->normalize_excerpt($excerpt, $content_html);
		$slug         = isset($draft['slug']) ? sanitize_title($draft['slug']) : sanitize_title($title);
		$category_slugs = isset($draft['category_slugs']) && is_array($draft['category_slugs']) ? $draft['category_slugs'] : array();
		$tag_slugs      = isset($draft['tag_slugs']) && is_array($draft['tag_slugs']) ? $draft['tag_slugs'] : array();
		$review_notes = isset($draft['review_notes']) ? sanitize_textarea_field($draft['review_notes']) : '';
		$image_focus  = isset($draft['image_focus']) ? sanitize_textarea_field($draft['image_focus']) : '';
		$image_alt    = isset($draft['image_alt']) ? sanitize_text_field($draft['image_alt']) : '';

		if (empty($title)) {
			$title = __('Generated draft article', 'ai-blog-writer');
		}

		if (empty($slug)) {
			$slug = sanitize_title($title);
		}

		if (empty($image_focus)) {
			$image_focus = $title;
		}

		if (empty($image_alt)) {
			$image_alt = $title . ' featured image';
		}

		if (empty($category_slugs) && ! empty($draft['category_name'])) {
			$legacy_category_slug = $this->resolve_term_slug_from_name($draft['category_name'], 'category');
			if ('' !== $legacy_category_slug) {
				$category_slugs[] = $legacy_category_slug;
			}
		}

		if (empty($tag_slugs) && ! empty($draft['tags']) && is_array($draft['tags'])) {
			$tag_slugs = $draft['tags'];
		}

		$category_slugs = $this->normalize_term_slugs($category_slugs, 'category', 3);
		$tag_slugs      = $this->normalize_term_slugs($tag_slugs, 'post_tag', 6);
		$category_names = $this->get_term_names_from_slugs($category_slugs, 'category');
		$tag_names      = $this->get_term_names_from_slugs($tag_slugs, 'post_tag');
		$primary_category_name = ! empty($category_names[0]) ? $category_names[0] : '';

		return array(
			'title'         => $title,
			'slug'          => $slug,
			'excerpt'       => $excerpt,
			'content_html'  => wp_kses_post($content_html),
			'category_name' => $primary_category_name,
			'category_names'=> $category_names,
			'category_slugs'=> $category_slugs,
			'tags'          => $tag_names,
			'tag_names'     => $tag_names,
			'tag_slugs'     => $tag_slugs,
			'image_focus'   => $image_focus,
			'image_alt'     => $image_alt,
			'review_notes'  => $review_notes,
		);
	}

	/**
	 * Normalize generated SEO metadata with fallbacks.
	 *
	 * @param array $seo_meta Generated SEO metadata.
	 * @param array $draft Normalized draft.
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private function normalize_seo_metadata($seo_meta, $draft, $settings = array()) {
		$allow_title_updates = ! isset($settings['allow_title_updates']) || ! empty($settings['allow_title_updates']);
		$meta_title = isset($seo_meta['meta_title']) ? sanitize_text_field($seo_meta['meta_title']) : '';
		$meta_description = isset($seo_meta['meta_description']) ? sanitize_textarea_field($seo_meta['meta_description']) : '';

		if (! $allow_title_updates || '' === $meta_title) {
			$meta_title = $draft['title'];
		}

		if ('' === $meta_description) {
			$meta_description = $draft['excerpt'];
		}

		return array(
			'meta_title' => $this->truncate_text($meta_title, 60),
			'meta_description' => $this->truncate_text($meta_description, 155),
		);
	}

	/**
	 * Save generated meta title and description to common SEO fields.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_title Generated meta title.
	 * @param string $meta_description Generated meta description.
	 * @return void
	 */
	private function sync_seo_plugin_meta($post_id, $meta_title, $meta_description) {
		$meta_title = sanitize_text_field($meta_title);
		$meta_description = sanitize_textarea_field($meta_description);

		update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
		update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
		update_post_meta($post_id, 'rank_math_title', $meta_title);
		update_post_meta($post_id, 'rank_math_description', $meta_description);
	}

	/**
	 * Normalize HTML article content.
	 *
	 * @param string $content_html Raw HTML.
	 * @param string $title Draft title.
	 * @return string
	 */
	private function normalize_html_content($content_html, $title) {
		$content_html = trim($content_html);

		if (empty($content_html)) {
			$content_html = '<p>' . esc_html__('No article content was returned, so this fallback paragraph was added.', 'ai-blog-writer') . '</p>';
		}

		if (wp_strip_all_tags($content_html) === $content_html) {
			$paragraphs = preg_split("/\n{2,}/", $content_html);
			$content_html = '';

			foreach ($paragraphs as $paragraph) {
				$paragraph = trim($paragraph);
				if ('' === $paragraph) {
					continue;
				}

				$content_html .= '<p>' . esc_html($paragraph) . '</p>' . "\n";
			}
		}

		if (false === stripos($content_html, '<h2')) {
			$content_html = '<p>' . esc_html($title) . '</p>' . "\n" . '<h2>' . esc_html__('Overview', 'ai-blog-writer') . '</h2>' . "\n" . $content_html;
		}

		return trim($content_html);
	}

	/**
	 * Create a clean excerpt.
	 *
	 * @param string $excerpt Raw excerpt.
	 * @param string $content_html Content HTML.
	 * @return string
	 */
	private function normalize_excerpt($excerpt, $content_html) {
		$excerpt = trim($excerpt);

		if ('' === $excerpt) {
			$excerpt = wp_trim_words(wp_strip_all_tags($content_html), 28, '...');
		}

		if (mb_strlen($excerpt) > 220) {
			$excerpt = mb_substr($excerpt, 0, 217) . '...';
		}

		return $excerpt;
	}

	/**
	 * Truncate a text value to the requested length.
	 *
	 * @param string $text Raw text.
	 * @param int    $max_length Max length.
	 * @return string
	 */
	private function truncate_text($text, $max_length) {
		$text = trim(wp_strip_all_tags((string) $text));
		$max_length = max(1, (int) $max_length);

		if (mb_strlen($text) <= $max_length) {
			return $text;
		}

		return rtrim(mb_substr($text, 0, max(1, $max_length - 3))) . '...';
	}

	/**
	 * Normalize a list of term slugs and keep only existing terms.
	 *
	 * @param array  $slugs Raw term slug array.
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $max_items Maximum number of items.
	 * @return array
	 */
	private function normalize_term_slugs($slugs, $taxonomy, $max_items) {
		$clean_slugs = array();

		foreach ((array) $slugs as $slug) {
			$slug = sanitize_title((string) $slug);
			if ('' === $slug) {
				continue;
			}

			$term = get_term_by('slug', $slug, $taxonomy);
			if ($term instanceof WP_Term) {
				$clean_slugs[] = $term->slug;
			}
		}

		return array_slice(array_values(array_unique($clean_slugs)), 0, max(0, (int) $max_items));
	}

	/**
	 * Resolve a term slug from its name.
	 *
	 * @param string $name Term name.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function resolve_term_slug_from_name($name, $taxonomy) {
		$name = trim((string) $name);

		if ('' === $name) {
			return '';
		}

		$term = get_term_by('name', $name, $taxonomy);

		if (! $term instanceof WP_Term) {
			$term = get_term_by('slug', sanitize_title($name), $taxonomy);
		}

		return $term instanceof WP_Term ? $term->slug : '';
	}

	/**
	 * Resolve existing term IDs from slugs.
	 *
	 * @param array  $slugs Term slugs.
	 * @param string $taxonomy Taxonomy name.
	 * @return int[]
	 */
	private function resolve_term_ids_from_slugs($slugs, $taxonomy) {
		$term_ids = array();

		foreach ((array) $slugs as $slug) {
			$term = get_term_by('slug', sanitize_title((string) $slug), $taxonomy);
			if ($term instanceof WP_Term) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		return array_values(array_unique(array_filter($term_ids)));
	}

	/**
	 * Resolve term names from slugs.
	 *
	 * @param array  $slugs Term slugs.
	 * @param string $taxonomy Taxonomy name.
	 * @return string[]
	 */
	private function get_term_names_from_slugs($slugs, $taxonomy) {
		$names = array();

		foreach ((array) $slugs as $slug) {
			$term = get_term_by('slug', sanitize_title((string) $slug), $taxonomy);
			if ($term instanceof WP_Term) {
				$names[] = $term->name;
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * Apply categories and tags to the post without creating new terms.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $draft Normalized draft payload.
	 * @return void
	 */
	private function assign_terms_to_post($post_id, $draft) {
		$settings     = $this->get_settings();
		$allow_category_updates = ! empty($settings['allow_category_updates']);
		$allow_tag_updates      = ! empty($settings['allow_tag_updates']);

		if ($allow_category_updates) {
			$category_ids = $this->resolve_term_ids_from_slugs($draft['category_slugs'], 'category');

			if (! empty($settings['default_category_id'])) {
				$category_ids[] = (int) $settings['default_category_id'];
			}

			$category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids))));

			if (empty($category_ids)) {
				$category_ids[] = (int) get_option('default_category', 1);
			}

			wp_set_post_terms($post_id, $category_ids, 'category', false);
		}

		if ($allow_tag_updates) {
			$tag_ids = $this->resolve_term_ids_from_slugs($draft['tag_slugs'], 'post_tag');

			if (! empty($settings['default_tag_id'])) {
				$tag_ids[] = (int) $settings['default_tag_id'];
			}

			$tag_ids = array_values(array_unique(array_filter(array_map('intval', $tag_ids))));

			wp_set_post_terms($post_id, $tag_ids, 'post_tag', false);
		}
	}

	/**
	 * Sanitize an on/off checkbox setting.
	 *
	 * @param mixed $value Raw toggle value.
	 * @return int
	 */
	private function sanitize_toggle_setting($value) {
		return (! empty($value) && '0' !== (string) $value) ? 1 : 0;
	}

	/**
	 * Keep disabled fields unchanged after AI review.
	 *
	 * @param array $original Original generated draft.
	 * @param array $reviewed Reviewed draft.
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private function enforce_locked_draft_fields($original, $reviewed, $settings) {
		$original = is_array($original) ? $original : array();
		$reviewed = is_array($reviewed) ? $reviewed : array();

		if (empty($settings['allow_title_updates'])) {
			$reviewed['title'] = isset($original['title']) ? $original['title'] : '';
		}

		if (empty($settings['allow_category_updates'])) {
			$reviewed['category_slugs'] = isset($original['category_slugs']) && is_array($original['category_slugs']) ? $original['category_slugs'] : array();
		}

		if (empty($settings['allow_tag_updates'])) {
			$reviewed['tag_slugs'] = isset($original['tag_slugs']) && is_array($original['tag_slugs']) ? $original['tag_slugs'] : array();
		}

		if (empty($settings['allow_image_updates'])) {
			$reviewed['image_focus'] = isset($original['image_focus']) ? $original['image_focus'] : '';
			$reviewed['image_alt'] = isset($original['image_alt']) ? $original['image_alt'] : '';
		}

		return $reviewed;
	}

	/**
	 * Enforce field permissions on normalized payload.
	 *
	 * @param array $draft Normalized draft.
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private function apply_draft_field_permissions($draft, $settings) {
		if (empty($settings['allow_category_updates'])) {
			$draft['category_name'] = '';
			$draft['category_names'] = array();
			$draft['category_slugs'] = array();
		}

		if (empty($settings['allow_tag_updates'])) {
			$draft['tags'] = array();
			$draft['tag_names'] = array();
			$draft['tag_slugs'] = array();
		}

		if (empty($settings['allow_image_updates'])) {
			$draft['image_focus'] = '';
			$draft['image_alt'] = '';
		}

		return $draft;
	}

	/**
	 * Return term options for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return WP_Term[]
	 */
	private function get_taxonomy_term_options($taxonomy) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		return is_array($terms) ? $terms : array();
	}

	/**
	 * Sanitize prompt settings while preserving HTML tags.
	 *
	 * @param mixed $value Submitted prompt value.
	 * @return string
	 */
	private function sanitize_prompt_setting($value) {
		if (is_array($value) || is_object($value)) {
			return '';
		}

		$value = wp_check_invalid_utf8((string) $value);

		if (0 === strpos($value, self::PROMPT_STORAGE_PREFIX)) {
			return $value;
		}

		return self::PROMPT_STORAGE_PREFIX . base64_encode($value);
	}

	/**
	 * Decode a stored prompt setting value.
	 *
	 * @param mixed $value Stored prompt value.
	 * @return string
	 */
	private function decode_prompt_setting($value) {
		if (is_array($value) || is_object($value)) {
			return '';
		}

		$value = wp_check_invalid_utf8((string) $value);

		if (0 !== strpos($value, self::PROMPT_STORAGE_PREFIX)) {
			return $value;
		}

		$encoded = substr($value, strlen(self::PROMPT_STORAGE_PREFIX));
		$decoded = base64_decode($encoded, true);

		if (false === $decoded) {
			return '';
		}

		return wp_check_invalid_utf8($decoded);
	}

	/**
	 * Render script that encodes the text prompt before settings submit.
	 *
	 * @return void
	 */
	private function render_prompt_submit_script() {
		?>
		<script>
			(function () {
				const prefix = <?php echo wp_json_encode(self::PROMPT_STORAGE_PREFIX); ?>;
				const form = document.querySelector('form[action="options.php"]');
				const promptField = document.getElementById('aibw_text_prompt');

				if (!form || !promptField) {
					return;
				}

				const encodeUtf8ToBase64 = (value) => window.btoa(unescape(encodeURIComponent(value)));

				form.addEventListener('submit', () => {
					if (promptField.value.indexOf(prefix) === 0) {
						return;
					}

					promptField.value = prefix + encodeUtf8ToBase64(promptField.value);
				});
			})();
		</script>
		<?php
	}

	/**
	 * Sanitize a saved default term setting.
	 *
	 * @param mixed  $term_id Submitted term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return int
	 */
	private function sanitize_term_setting($term_id, $taxonomy) {
		$term_id = absint($term_id);

		if (0 === $term_id) {
			return 0;
		}

		$term = get_term($term_id, $taxonomy);

		return $term instanceof WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Execute a JSON API request to OpenAI.
	 *
	 * @param string $url Endpoint URL.
	 * @param array  $payload Request body.
	 * @param string $api_key OpenAI API key.
	 * @param int    $timeout Timeout in seconds.
	 * @return array|\WP_Error
	 */
	private function perform_openai_request($url, $payload, $api_key, $timeout = 120) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . trim($api_key),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode($payload),
			)
		);

		if (is_wp_error($response)) {
			return new WP_Error('aibw_http_error', $response->get_error_message());
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body        = wp_remote_retrieve_body($response);
		$data        = json_decode($body, true);

		if ($status_code < 200 || $status_code >= 300) {
			$error_message = ! empty($data['error']['message']) ? $data['error']['message'] : __('OpenAI returned an unexpected error.', 'ai-blog-writer');
			return new WP_Error('aibw_api_error', sanitize_text_field($error_message));
		}

		if (! is_array($data)) {
			return new WP_Error('aibw_api_invalid_body', __('OpenAI returned an invalid JSON response.', 'ai-blog-writer'));
		}

		return $data;
	}

	/**
	 * Extract text from a Responses API payload.
	 *
	 * @param array $response API response.
	 * @return string
	 */
	private function extract_text_from_response($response) {
		if (! empty($response['output_parsed']) && is_array($response['output_parsed'])) {
			return wp_json_encode($response['output_parsed']);
		}

		if (! empty($response['output_text']) && is_string($response['output_text'])) {
			return trim($response['output_text']);
		}

		if (empty($response['output']) || ! is_array($response['output'])) {
			return '';
		}

		foreach ($response['output'] as $item) {
			if (empty($item['content']) || ! is_array($item['content'])) {
				continue;
			}

			foreach ($item['content'] as $content_item) {
				if (! empty($content_item['parsed']) && is_array($content_item['parsed'])) {
					return wp_json_encode($content_item['parsed']);
				}

				if (! empty($content_item['text']) && is_string($content_item['text'])) {
					return trim($content_item['text']);
				}
			}
		}

		return '';
	}

	/**
	 * Decode a JSON string, including simple fenced-code fallback.
	 *
	 * @param string $raw_text Raw text response.
	 * @return array|null
	 */
	private function decode_json_payload($raw_text) {
		$decoded = json_decode($raw_text, true);

		if (is_array($decoded)) {
			return $decoded;
		}

		if (preg_match('/\{.*\}/s', $raw_text, $matches)) {
			$decoded = json_decode($matches[0], true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * JSON schema for the first pass.
	 *
	 * @return array
	 */
	private function get_generation_schema() {
		return array(
			'name'   => 'blog_post_generation',
			'schema' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array('title', 'slug', 'excerpt', 'content_html', 'category_slugs', 'tag_slugs', 'image_focus', 'image_alt'),
				'properties'           => array(
					'title'         => array('type' => 'string', 'minLength' => 10, 'maxLength' => 120),
					'slug'          => array('type' => 'string', 'minLength' => 3, 'maxLength' => 120),
					'excerpt'       => array('type' => 'string', 'minLength' => 80, 'maxLength' => 220),
					'content_html'  => array('type' => 'string', 'minLength' => 800),
					'category_slugs' => array(
						'type'     => 'array',
						'minItems' => 0,
						'maxItems' => 3,
						'items'    => array('type' => 'string', 'minLength' => 1, 'maxLength' => 80),
					),
					'tag_slugs'      => array(
						'type'     => 'array',
						'minItems' => 0,
						'maxItems' => 6,
						'items'    => array('type' => 'string', 'minLength' => 1, 'maxLength' => 80),
					),
					'image_focus'   => array('type' => 'string', 'minLength' => 20, 'maxLength' => 320),
					'image_alt'     => array('type' => 'string', 'minLength' => 12, 'maxLength' => 160),
				),
			),
		);
	}

	/**
	 * JSON schema for validation pass.
	 *
	 * @return array
	 */
	private function get_validation_schema() {
		return array(
			'name'   => 'blog_post_review',
			'schema' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array('title', 'slug', 'excerpt', 'content_html', 'category_slugs', 'tag_slugs', 'image_focus', 'image_alt', 'review_notes'),
				'properties'           => array(
					'title'         => array('type' => 'string', 'minLength' => 10, 'maxLength' => 120),
					'slug'          => array('type' => 'string', 'minLength' => 3, 'maxLength' => 120),
					'excerpt'       => array('type' => 'string', 'minLength' => 80, 'maxLength' => 220),
					'content_html'  => array('type' => 'string', 'minLength' => 800),
					'category_slugs' => array(
						'type'     => 'array',
						'minItems' => 0,
						'maxItems' => 3,
						'items'    => array('type' => 'string', 'minLength' => 1, 'maxLength' => 80),
					),
					'tag_slugs'      => array(
						'type'     => 'array',
						'minItems' => 0,
						'maxItems' => 6,
						'items'    => array('type' => 'string', 'minLength' => 1, 'maxLength' => 80),
					),
					'image_focus'   => array('type' => 'string', 'minLength' => 20, 'maxLength' => 320),
					'image_alt'     => array('type' => 'string', 'minLength' => 12, 'maxLength' => 160),
					'review_notes'  => array('type' => 'string', 'minLength' => 20, 'maxLength' => 500),
				),
			),
		);
	}

	/**
	 * JSON schema for SEO metadata generation.
	 *
	 * @return array
	 */
	private function get_seo_metadata_schema() {
		return array(
			'name'   => 'blog_post_seo_metadata',
			'schema' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array('meta_title', 'meta_description'),
				'properties'           => array(
					'meta_title'       => array('type' => 'string', 'minLength' => 10, 'maxLength' => 60),
					'meta_description' => array('type' => 'string', 'minLength' => 40, 'maxLength' => 155),
				),
			),
		);
	}
}
