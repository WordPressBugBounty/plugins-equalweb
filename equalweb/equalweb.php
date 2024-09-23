<?php
/**
 * Plugin Name: EqualWeb
 * Plugin URI:  https://wordpress.org/plugins/equalweb
 * Description: Equalweb's digital accessibility solution wordpress plugin allowing you to easily implement your accessibility code with your wordpress website.
 * Version:     1.0
 * Author:      Equalweb Accessibility
 * Author URI:  https://www.equalweb.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: equalweb
 * 
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU
 * General Public License version 2.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

/**
 * Restrict direct access to the file, for security purpose.
 */
defined('ABSPATH') or die('You can not access it directly');

if( !class_exists('EqualWeb_Plugin') )  {

/**
 * Defining class for the plugin
 * for better code structure using OOP
 */
class EqualWeb_Plugin {

    // Defining language domain for translation
	const DOMAIN	= 'equalweb';

	// Defining some values to be used inside plugin code
	public static $config = [
								'group'		=> 'equalweb-accessibility',
								'title'		=> 'EqualWeb',
								'page'		=> 'equalweb',
								'pagelink'	=> 'options-general.php?page=equalweb',
								'prefix'	=> 'equalweb',
							];
							
	// Defining plugin setting fields with their default values
	public static $settings	= [
                                'enable'    => 0,
                                'code'      => '',
							  ];

    // Class constructor which attaches plugins functions and do other initiating tasks
	public function __construct() {
		
        // Back-end Hooks
		register_activation_hook(__FILE__, [$this, 'activate']);
		add_filter('plugin_action_links_'.plugin_basename(__FILE__), [$this, 'settings_link']);
		add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'redirect']);
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_enqueue_scripts', [$this, 'admin_css']);

		// Front-end Hooks
		add_action('wp_footer', [$this, 'code']);

	}

	/**
	 * Function to be called when plugin
	 * is activated to save option for redirection
	 **/
	function activate() {
        extract( static::$config );
		add_option($prefix.'_activation_redirect', true);
	}

    /**
	 * Redirect to plugin settings page
	 * when plugin is activated
	 **/
	function redirect() {
        extract( static::$config );
		if (get_option($prefix.'_activation_redirect', false)) {
			delete_option($prefix.'_activation_redirect');
			wp_redirect($pagelink);
			exit;
		}
	}
	
	/**
	 * Adds plugin settings link to plugins admin page
	 */
	function settings_link( $links ) { 
		extract( static::$config );
		$new = '<a href="'.$pagelink.'">Settings</a>';
		array_unshift($links, $new); 
		return $links;
	}

	/**
	 * To get plugin setting field values
	 */
	function options() {
		extract( static::$config );
		$options = [];
		foreach( static::$settings as $field => $default ) {
			$options[$field] = get_option( $prefix.'_'.$field, $default );
		}
		return $options;
	}

	/**
	 * Registers plugin settings fields
	 */
	function register_settings() {
		extract( static::$config );
		foreach( static::$settings as $field => $default ) {
			register_setting( $group, $prefix.'_'.$field );
		}
	}
	
	/**
	 * Adds a new menu item for
	 * plugin settings page in WP admin menu
	 * as a child of Settings menu
	 */
	function admin_menu() {
		extract(static::$config);
		add_options_page($title, $title, 'administrator', $page, [$this, 'admin_settings']);
	}
	
	/**
	 * Displays the content of plugin settings page in WordPress admin
	 */
	function admin_settings() {
		extract( static::$config );
		extract( $this->options() );
		?>
		<div class="equalweb-settings wrap <?php echo $prefix ?>-settings">
			<div class="<?php echo $prefix ?>-settings-inner">
				<div class="left-side"> 
					<div class="top-logo">
						<img src="<?php echo plugins_url('assets/img/wp-plugin-logo.png', __FILE__ ); ?>" class="big">
						<img src="<?php echo plugins_url('assets/img/wp-plugin-logo-small.png', __FILE__ ); ?>" class="small">
					</div>
					<div class="main-text-wrap">
						<h1>Equalweb is an AI-powered solution (SaaS) and online remediation widget, created to improve accessibility and monitoring of any website</h1>
						<h2>Installation</h2>
						<p class="text-2">Just insert one line of code and we will do the rest!</p>
						<h3>Please make sure you create an account on equalweb.com and register your website domain.<br> Otherwise the plugin will not function.</h3>
						<ul>
							<li><img src="<?php echo plugins_url('assets/img/wp-plugin-step-1.png', __FILE__ ); ?>"> Create an account on <a href="https://login.equalweb.com/?page=registration&ref=1489&a_aid=114411&platform=wordpress" target="_blank">login.equalweb.com</a> <br />and register your website domain</li>
							<li><img src="<?php echo plugins_url('assets/img/wp-plugin-step-2.png', __FILE__ ); ?>"> Copy-paste EqualWeb accessibility <br />code in your website code</li>
							<li><img src="<?php echo plugins_url('assets/img/wp-plugin-step-3.png', __FILE__ ); ?>"> Click Save<br /> And you are all set!</li>
						</ul>
						<img src="<?php echo plugins_url('assets/img/wp-plugin-arrow-down_anim.gif', __FILE__ ); ?>" class="equal-arrow">
					</div>
					<form method="post" action="options.php">
						<?php settings_fields( $group ); ?>
						<?php do_settings_sections( $group ); ?>
						<div class="accessibility-js-code">
							<div class="title-div"><img src="<?php echo plugins_url('assets/img/wp-plugin-js-code.png', __FILE__ ); ?>"> Accessibility JS Code</div>
							<textarea name="<?php echo $prefix; ?>_code" rows="6" placeholder="Paste your accessibility code here"><?php echo $code; ?></textarea>
						</div>
						<div class="accessibility-activate">
							<div class="title-div">Activate</div>
							<label>
								<select class="<?php echo $prefix; ?>_enable" name="<?php echo $prefix; ?>_enable">
									<option value="1" <?php selected( $enable, 1); ?>>Enable</option>
									<option value="0" <?php selected( $enable, 0); ?>>Disable</option>
								</select>
							</label>
							<div class="submit-wrap">
								<?php submit_button(); ?>
							</div>
						</div>
					</form>
				</div>
				<div class="right-side">
					<div class="top-banner"><img src="<?php echo plugins_url('assets/img/wp-plugin-banner.jpg', __FILE__ ); ?>"></div>
					<p><img src="<?php echo plugins_url('assets/img/wp-plugin-security-icon.png', __FILE__ ); ?>">Our widget is fully protected from security threats. We do not extract any data from your website or make any changes to it whatsoever.</p>
					<?php if (!$enable) { ?>
						<div class="movie-wrap">
							<h2>Installation Video Guide</h2>
							<video src="<?php echo plugins_url('assets/video/vid.mp4', __FILE__ ); ?>" autoplay controls></video>
						</div>
					<?php } ?>
				</div>
			</div>
		</div>
		<?php		
	}
	
	/**
	 * Hooks into footer
     * To add accessibility code provided by EqualWeb
	 */
	function code() {
		extract( static::$config );
        extract( $this->options() );
        if ($enable) {
            echo $code;
        }
	}
	
	/**
	 * Includes plugin CSS file in WP admin
	 */
	function admin_css() {
		$screen = get_current_screen();
        if ( $screen->id == 'settings_page_equalweb' ) {
			wp_enqueue_style('equalweb', plugins_url('assets/css/style.css', __FILE__ ));
		}
	}

}

}

// Initialize the class object
$equalweb = new EqualWeb_Plugin();