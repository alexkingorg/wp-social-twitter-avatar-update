<?php

/*
Plugin Name: Social Twitter Avatar Update
Plugin URI: https://github.com/alexkingorg/wp-social-twitter-avatar-update
Description: Over time people change their Twitter avatars. This script aims to update them for your Social comments.
Version: 1.0
Author: Alex King
Author URI: http://alexking.org/
*/

if (!class_exists('Social_Twitter_Avatar_Update')) {

load_plugin_textdomain('social-twitter-avatar-update', false, dirname(plugin_basename(__FILE__)).'/languages/');

add_action('admin_init', array('Social_Twitter_Avatar_Update', 'controller'));
add_filter('plugin_action_links', array('Social_Twitter_Avatar_Update', 'plugin_action_links'), 10, 2);

class Social_Twitter_Avatar_Update {

	public static function plugin_action_links($links, $file) {
		if (basename($file) == basename(__FILE__)) {
			$nonce = wp_create_nonce('social-twitter-avatar-update');
			$settings_link = '<a href="plugins.php?social_action=update-twitter-avatars&wp_nonce='.urlencode($nonce).'">'.__('Run', 'social-twitter-avatar-update').'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	public static function controller() {
		if (isset($_GET['social_action'])) {
			switch ($_GET['social_action']) {
				case 'update-twitter-avatars':
					if (wp_verify_nonce($_GET['wp_nonce'], 'social-twitter-avatar-update')) {
						self::run();
					}
				break;
			}
		}
	}

	public static function run() {
		// only run if Social is loaded
		if (!class_exists('Social')) {
			wp_die(__('The Social plugin must be enabled before we can run.', 'social-twitter-avatar-update'));
		}
	
		// let's try not to time out
		set_time_limit(0);
		
		$commenter_avatars = array();
		for ($i = 0; $i != -1; $i++) {
			$commenters = self::get_commenters($i);
			if (!empty($commenters)) {
				$avatars = self::get_avatars($commenters);
				$commenter_avatars = array_merge($commenter_avatars, $avatars);
			}
			else {
				$i = -2; // stop loop
			}
		}

		// yes, this is going to run a SQL query in a loop
		foreach ($commenter_avatars as $screen_name => $avatar_url) {
			self::update_avatars_for_commenter($screen_name, $avatar_url);
		}
		
		wp_die(sprintf(__('Ok, all %d avatars should be updated', 'social-twitter-avatar-update'), count($commenter_avatars)));
	}
	
	public static function get_commenters($offset = 0) {
		global $wpdb;
		$offset = $offset * 100;
		$results = $wpdb->get_results("
			SELECT comment_author, comment_ID
			FROM $wpdb->comments
			WHERE comment_author_email LIKE 'twitter.%@example.com'
			GROUP BY comment_author
			ORDER BY comment_ID
			LIMIT 100
			OFFSET $offset
		");
		$commenters = array();
		if (!empty($results)) {
			foreach ($results as $commenter) {
				$commenters[] = $commenter->comment_author;
			}
		}
		return $commenters;
	}
	
	public static function get_avatars($screen_names) {
		$avatars = array();
		if (empty($screen_names)) {
			return $avatars;
		}

		$social_twitter = Social::instance()->service('twitter');

		// If we don't have a Social_Twitter object, get out
		if (is_null($social_twitter)) {
			return;
		}

		// If we don't have any Social Twitter accounts, get out
		$social_accounts = $social_twitter->accounts();
		if (empty($social_accounts)) {
			return;
		}

		// pull the first account
		foreach ($social_accounts as $obj_id => $acct_obj) {
			$social_acct = $acct_obj;
			break;
		}

		// Make the API request & handle response
		$response = $social_twitter->request($social_acct, '1.1/users/lookup', array(
			'screen_name' => implode(',', $screen_names),
			'include_entities' => false
		));
		$content = $response->body();
		if ($content->result == 'success') {
			foreach ($content->response as $user) {
				$avatar_url = str_replace('normal.', 'bigger.', $user->profile_image_url);
				$avatars[$user->screen_name] = $avatar_url;
			}
		}
		return $avatars;
	}
	
	public static function update_avatars_for_commenter($screen_name, $avatar_url) {
		global $wpdb;
		$comment_ids = array();

		// the % char in a string causes $wpdb->prepare to crap out
		$safe_query = $wpdb->prepare("
			SELECT comment_ID
			FROM $wpdb->comments
			WHERE comment_author = %s
			AND comment_author_email LIKE 'twitter.@example.com'
		", $screen_name);
		$safe_query = str_replace('twitter.@example.com', 'twitter.%@example.com', $safe_query);
		$results = $wpdb->get_results($safe_query);

		if (empty($results)) {
			return;
		}
		foreach ($results as $result) {
			$comment_ids[] = $result->comment_ID;
		}

		$query = $wpdb->query($wpdb->prepare("
			UPDATE $wpdb->commentmeta
			SET meta_value = %s
			WHERE meta_key = 'social_profile_image_url'
			and comment_id IN (".implode(',', $comment_ids).")
		", $avatar_url));
	}

}

} // end class_exists check
