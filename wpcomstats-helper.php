<?php

/*
Plugin Name: Wordpress.com Stats Helper
Plugin URI: http://vlad.bailescu.ro/wordpress/plugin-stats-helper-functions-and-widgets/
Description: This plugin helps you extract data from wordpress.com stats and use it across your blog.
Version: 0.5.5.3
Author: Vlad Bailescu
Author URI: http://vlad.bailescu.ro/

CHANGES
2008.11.15 - Version 0.5.5.3, Added array checks before foreach-es (was causing some ugly PHP warnings for some users).
2008.10.05 - Version 0.5.5.2, Added missing default to most visited list: showing both posts and pages.
2008.10.05 - Version 0.5.5.1, Small fix for options handling
2008.10.05 - Version 0.5.5, Compatibility fix for WP 2.6.2; Added default options to configuration, optional title truncation and ability to disable promotional link. Code contributed by Michael Tyson <mike@tyson.id.au> http://michael.tyson.id.au/
2008.06.12 - Version 0.5.4, Option cache gets cleared of expired elements on each save (was getting too big and causing crashes).
	Most visited posts title and permalink are no longer retrieved from stats.wordpress.com since they sometimes return wrong data.
2008.05.06 - Version 0.5.3, Removed passing by reference in foreach, not supported in PHP4
2008.05.06 - Version 0.5.2, Fixed version numbering
2008.05.06 - Version 0.5.1, Removed try... catch blocks as they're only supported since PHP5
2008.05.03 - Version 0.5, Added possibility to filter most visited items by type (posts, pages or both).
2008.04.03 - Version 0.4, Added possibility to display stats in a given timeframe (day, week, month, all time). Changed visit count to allow per post/page stats. Changed visits widget to automatically detect when to display stats for the whole blog and when for a single post/page.
2008.03.09 - Version 0.3, Added function and widget for blog visits.
2008.02.25 - Version 0.2, Included internal, option-based caching mechanism. Added possibility to hide views count.
2008.02.23 - Version 0.1, Most visited posts function and widget
*/

require_once(ABSPATH . 'wp-includes/class-snoopy.php');

// Splits a string into an array of tokens, delimited by delimiter char
// tokens in input string containing the delimiter character or the literal escape character are surrounded by a pair of escape characteres
// a literal escape character is produced by the escape character appearing twice in sequence
// default delimiter character and escape character are suitable for Excel-exported CSV formatted lines
// Source: http://www.php.net/manual/en/function.explode.php#75876
function splitWithEscape ($str, $delimiterChar = ',', $escapeChar = '"') {
	$len = strlen($str);
	$tokens = array();
	$i = 0;
	$inEscapeSeq = false;
	$currToken = '';
	while ($i < $len) {
		$c = substr($str, $i, 1);
		if ($inEscapeSeq) {
			if ($c == $escapeChar) {
				// lookahead to see if next character is also an escape char
				if ($i == ($len - 1)) {
					// c is last char, so must be end of escape sequence
					$inEscapeSeq = false;
				} else if (substr($str, $i + 1, 1) == $escapeChar) {
					// append literal escape char
					$currToken .= $escapeChar;
					$i++;
				} else {
					// end of escape sequence
					$inEscapeSeq = false;
				}
			} else {
				$currToken .= $c;
			}
		} else {
			if ($c == $delimiterChar) {
				// end of token, flush it
				array_push($tokens, $currToken);
				$currToken = '';
			} else if ($c == $escapeChar) {
				// begin escape sequence
				$inEscapeSeq = true;
			} else {
				$currToken .= $c;
			}
		}
		$i++;
	}
	// flush the last token
	array_push($tokens, $currToken);
	return $tokens;
}

// Simple cache based on wp_cache and doubled by options

// Cache getter
function opt_cache_get($key) {
	// Check wp_cache
	$result = wp_cache_get($key);
	if ($result) {
		return $result;
	}
	// Check option-based cache
	$opt_cache = get_option('opt_cache');
	if ($opt_cache) {
		$entry = $opt_cache[$key];
		if ($entry && $entry['expt'] && $entry['expt'] > time() ) {
			wp_cache_set($key, $entry['value'], '', $entry['expt'] - time());
			return $entry['value'];
		}
	}
	return false;
}

// Cache setter
function opt_cache_set($key, $value, $expire = 500) {
	// Set wp_cache
	wp_cache_set($key, $value, '', $expire);
	// Set option-based cache
	$opt_cache = get_option('opt_cache');
	if (!$opt_cache) { $opt_cache = array(); }
	$opt_cache[$key] = array('value' => $value, 'expt' => time() + $expire);
	if (is_array($opt_cache)) {
		foreach($opt_cache as $key => $val) {
			if ($val['expt'] < time()) {
				unset($opt_cache[$key]);
			}
		}
	}
	update_option('opt_cache', $opt_cache);
}

// Cache cleaner
function opt_cache_clear() {
	delete_option('opt_cache');
}

// Checks to see if wordpress.com stats plugin is installed and the api key is defined
function wpcomstats_api_key() {
	// Check if worpress.com stats plugin is installed
	if (!function_exists('stats_get_api_key') || !function_exists('stats_get_option')) {
		echo 'Wordpress.com stats not installed!';
		return null;
	}
	// Check if the API key is defined
	$api_key = stats_get_api_key();
	if (empty($api_key)) {
		echo 'API Key not defined!';
		return null;
	}
	return $api_key;
}

// Sanitizes days. Returns one of 0, 1, 7 or 30.
function wpcomstats_check_days($days) {
	if ($days != 1 && $days != 7 && $days != 30) {
		return 0;
	} else {
		return $days;
	}
}

// Returns string to be appended at the end of the widgets auto-titles
function wpcomstats_get_duration_str($days) {
	switch($days) {
		case 1:
			return ' Today';
		case 7:
			return ' This Week';
		case 30:
			return ' This Month';
		default:
			return '';
	}
}

// Helper function to list most visited posts and/or pages
function wpcomstats_most_visited($options) {
	// Sanitize days
	$days = wpcomstats_check_days($options['days']);
	// Check cache
	$cache_key = 'wpcomstats_most_visited_posts';
	if ($days != 0) {
		$cache_key .= '_'.$days;
	}
	$list = opt_cache_get($cache_key);
	if (!$list) {
		if (!$api_key = wpcomstats_api_key()) { return; }
		// Fetch the corresponding CSV
		$snoopy = new Snoopy();
		$snoopy->read_timeout = 2;
		if (@$snoopy->fetch('http://stats.wordpress.com/csv.php?'.
				'api_key='.$api_key.
				'&blog_id='.stats_get_option('blog_id').
				'&table=postviews&summarize=true'.
				'&days='.($days==0?'-1':$days).
				'&limit=30')) {
			$results = trim(str_replace("\r\n", "\n", $snoopy->results));
			// Look for Error at beginning of fetched text
			$err = strpos($results, 'Error');
			if ($err === false || $err != 0) {
				$rows = explode("\n", $results);
				$list = array();
				$postIds = '';
				for ($i = 1, $n = count($rows); $i < $n; $i++) {
					$cols = splitWithEscape($rows[$i]);
					$id = $cols[0];
					$postIds .= $id.' ';
					$name = $cols[1];
					$url = $cols[2];
					$count = $cols[3];
					$list['p'.$id] = array (
							'name' => $name,
							'url' => $url,
							'count' => $count);
				}
				$args = array ('include' => $postIds,
						'post_type' => 'any');
				$postList = get_posts($args);
				
				if (is_array($postList)) {
					foreach($postList as $post) {
						$list['p'.$post->ID]['post_type'] = $post->post_type;
						$list['p'.$post->ID]['name'] = $post->post_title;
						$list['p'.$post->ID]['url'] = get_permalink($post->ID);
					}
				}
				// Cache the resulting list
				opt_cache_set($cache_key, $list);
			}
		}
	}

	$before = $options['before']?$options['before']:'&raquo;';
	$after = $options['after']?$options['after']:'<br />';
	if (!$list || count($list) <= 0) {
		echo $before.'No data yet!'.$after;
		return;
	}
	$posts = $options['posts'];
	if ($posts > 25) { $posts = 25; }
	if ($posts <= 0) { $posts = 5; }
	$show_count = $options['show_count'];
	$show = $options['show'];
	if ($show != 'posts' && $show != 'pages' && $show != 'both') {
		$show = 'both';
	}
	reset($list);
	$i = 1;
	if (is_array($list)) {
		foreach($list as $key => $val) {
			if ( ( ($show == 'posts' || $show == 'both') && $val['post_type'] == 'post') ||
					( ($show == 'pages' || $show == 'both' ) && $val['post_type'] == 'page') ) {
				$name = $val['name'];
				$url = $val['url'];
				$count = $val['count'];
				if ($options['truncate_title'] && strlen($name) > 40) {
					// Shorten long post names to max 40 chars
					$short_name = substr($name, 0, 40).'...';
				} else {
					$short_name = $name;
				}
				$most_visited_posts .= $before.'<a href="'.$url.'" title="'.$name.'">'.
						$short_name.'</a>'.($show_count?' ('.$count.')':'').$after;
				if (++$i > $posts) {
					break;
				}
			}
		}
	}
	echo $most_visited_posts;
}

// Old helper function to list most visited posts, kept for backwards compatibility
function wpcomstats_most_visited_posts($posts = 5, $before = '&raquo;', $after = '<br />', 
		$show_count = true, $days = 0) {
	wpcomstats_most_visited(array('posts' => $posts, 'before' => $before, 
			'after' => $after, 'show_count' => $show_count, 'days' => $days));
}

function wpcomstats_visits($before = '&raquo;', $after = '<br />', $post_id = null, $days = 0) {
	// Sanitize days
	$days = wpcomstats_check_days($days);
	// Check cache
	$cache_key = 'wpcomstats_total_visits';
	if ($post_id) {
		$cache_key .= '_'.$post_id;
	}
	if ($days != 0) {
		$cache_key .= '_'.$days;
	}
	$rows = opt_cache_get($cache_key);
	if (!$rows) {
		if (!$api_key = wpcomstats_api_key()) { return; }
		// Fetch total visits
		$snoopy = new Snoopy();
		if (@$snoopy->fetch('http://stats.wordpress.com/csv.php?'.
				'api_key='.$api_key.
				'&blog_id='.stats_get_option('blog_id').
				($post_id?('&table=postviews&post_id='.$post_id):'').
				'&days='.($days==0?'-1':$days).
				'&summarize=true')) {
			$results = trim(str_replace("\r\n", "\n", $snoopy->results));
			$rows = explode("\n", $results);
			// Cache the results rows
			opt_cache_set($cache_key, $rows);
		}
	}
	if (!$rows || !$rows[1]) {
		echo $before.'No data yet!'.$after;
	} else {
		echo $before.$rows[1].$after;
	}
}

// Widget initialization
function wpcomstats_helper_init() {

	// Check to see if widgets are permitted
	if ( !function_exists('register_sidebar_widget') || 
			!function_exists('register_widget_control') ) {
		return;
	}

	// Define widget - no magic here
	function widget_wpcomstats_most_visited_posts($args) {
		extract($args);
		$options = get_option('widget_wpcomstats_most_visited_posts');
		$days = $options['days'];
		$duration = wpcomstats_get_duration_str($days);
		$show = $options['show'];
		$title = $options['title'];
		if (!$title || $title == '') { 
			$title = 'Most Visited ';
			if ($show == 'posts') {
				$title .= 'Posts';
			}
			if ($show == 'pages') {
				$title .= 'Pages';
			}
			$title .= $duration;
		}
		$posts = $options['posts'];
		if (!$posts || $posts == '') { $posts = 5; }
		$show_count = $options['show_count'];
		$truncate_title = $options['truncate_title'];
		$promote = $options['promote'];
		echo $before_widget;
		echo $before_title.$title.$after_title;
		echo '<ul>';
		echo wpcomstats_most_visited($options = array(
				'posts' => $posts,
				'before' => '<li>',
				'after' => '</li>',
				'show_count' => $show_count,
				'days' => $days,
				'show' => $show,
				'truncate_title' => $truncate_title));
		echo '</ul>';
		if ( $promote ) {
			print_credits();
		}
		echo $after_widget;
	}

	// Define widget configuration control
	function widget_wpcomstats_most_visited_posts_control() {
		$options = $newoption = get_option('widget_wpcomstats_most_visited_posts');
		if ( $_POST['widget_wpcomstats_most_visited_posts-submit'] ) {
			$newoptions['title'] = strip_tags(stripslashes($_POST['widget_wpcomstats_most_visited_posts-title']));
			$newoptions['posts'] = strip_tags(stripslashes($_POST['widget_wpcomstats_most_visited_posts-posts']));
			$newoptions['show_count'] = strip_tags(stripslashes($_POST['widget_wpcomstats_most_visited_posts-show_count']));
			$newoptions['truncate_title'] = strip_tags(stripslashes($_POST['widget_wpcomstats_most_visited_posts-truncate_title']));
			$newoptions['promote'] = strip_tags(stripslashes($_POST['widget_wpcomstats_most_visited_posts-promote']));
			$newoptions['days'] = strip_tags(stripslashes($_POST['widget_wpcomstats_most_visited_posts-days']));
			$newoptions['show'] = strip_tags(stripslashes($_POST['widget_wpcomstats_most_visited_posts-show']));
			if ( $options != $newoptions ) {
				$options = $newoptions;
				opt_cache_clear();
				update_option('widget_wpcomstats_most_visited_posts', $options);
			}
		}
?>
		<label for="widget_wpcomstats_most_visited_posts-title" style="line-height:25px;display:block;">
			<?php _e('Title:', 'widgets'); ?>
			<input type="text" id="widget_wpcomstats_most_visited_posts-title" name="widget_wpcomstats_most_visited_posts-title" value="<?php echo wp_specialchars($options['title'], true); ?>" />
		</label> 
		<label for="widget_wpcomstats_most_visited_posts-posts" style="line-height:25px;display:block;">
			<?php _e('Number of posts to show:', 'widgets'); ?>
			<input type="text" id="widget_wpcomstats_most_visited_posts-posts" name="widget_wpcomstats_most_visited_posts-posts" value="<?php echo wp_specialchars($options['posts'], true); ?>" />
		</label> 
		<label for="widget_wpcomstats_most_visited_posts-show_count" style="line-height:25px;display:block;">
			<input type="checkbox" id="widget_wpcomstats_most_visited_posts-show_count" name="widget_wpcomstats_most_visited_posts-show_count" value="true" <?php echo $options['show_count'] == 'true'?' checked':'';?>>
			<?php _e('Show Views Count', 'widgets'); ?>
		</label>
		<label for="widget_wpcomstats_most_visited_posts-truncate_title" style="line-height:25px;display:block;">
			<input type="checkbox" id="widget_wpcomstats_most_visited_posts-truncate_title" name="widget_wpcomstats_most_visited_posts-truncate_title" value="true" <?php echo $options['truncate_title'] == 'true'?' checked':'';?>>
			<?php _e('Trim titles to 40 characters', 'widgets'); ?>
		</label>
   	<label for="widget_wpcomstats_most_visited_posts-promote" style="line-height:25px;display:block;">
   		<input type="checkbox" id="widget_wpcomstats_most_visited_posts-promote" name="widget_wpcomstats_most_visited_posts-promote" value="true" <?php echo $options['promote'] == 'true'?' checked':'';?>>
   		<?php _e('Help promote Wordpress.com Stats Helper', 'widgets'); ?>
   	</label>
		<label for="widget_wpcomstats_most_visited_posts-days" style="line-height:25px;display:block;">
			<?php _e('Time Interval:', 'widgets'); ?>
			<select id="widget_wpcomstats_most_visited_posts-days" name="widget_wpcomstats_most_visited_posts-days">
				<option value="0"<?php echo $options['days'] == 0?' selected="selected"':'';?>>All</option>
				<option value="1"<?php echo $options['days'] == 1?' selected="selected"':'';?>>Today</option>
				<option value="7"<?php echo $options['days'] == 7?' selected="selected"':'';?>>Last Week</option>
				<option value="30"<?php echo $options['days'] == 30?' selected="selected"':'';?>>Last Month</option>
			</select>
		</label>
		<label for="widget_wpcomstats_most_visited_posts-show" style="line-height:25px;display:block;">
			<?php _e('Show:', 'widgets'); ?>
			<select id="widget_wpcomstats_most_visited_posts-show" name="widget_wpcomstats_most_visited_posts-show">
				<option value="both"<?php echo $options['show'] == 'both'?' selected="selected"':'';?>>Posts and Pages</option>
				<option value="posts"<?php echo $options['show'] == 'posts'?' selected="selected"':'';?>>Posts Only</option>
				<option value="pages"<?php echo $options['show'] == 'pages'?' selected="selected"':'';?>>Pages Only</option>
			</select>
		</label>
		<input type="hidden" name="widget_wpcomstats_most_visited_posts-submit" id="widget_wpcomstats_most_visited_posts-submit" value="true" />
<?php
	}
	
	// Define blog visits widget - no magic here, either
	function widget_wpcomstats_visits($args) {
		extract($args);
		$options = get_option('widget_wpcomstats_visits');
		$days = $options['days'];
		$duration = wpcomstats_get_duration_str($days);
		$title = $options['title'];
		if (!$title || $title == '') { 
			if (is_single() || is_page()) {
				$title = 'Page';
			} else {
				$title = 'Blog';
			}
			$title .= ' Visits'.$duration;
		}
		echo $before_widget;
		echo $before_title.$title.$after_title;
		echo '<ul>';
		if (is_single() || is_page()) {
			global $post;
			echo wpcomstats_visits('<li>', '</li>', $post->ID, $days);
		} else {
			echo wpcomstats_visits('<li>', '</li>', null, $days);
		}
		echo '</ul>';
		print_credits();
		echo $after_widget;
	}
	
		// Define widget configuration control
	function widget_wpcomstats_visits_control() {
		$options = $newoption = get_option('widget_wpcomstats_visits');
		if ( $_POST['widget_wpcomstats_visits-submit'] ) {
			$newoptions['title'] = strip_tags(stripslashes($_POST['widget_wpcomstats_visits-title']));
			$newoptions['days'] = strip_tags(stripslashes($_POST['widget_wpcomstats_visits-days']));
			if ( $options != $newoptions ) {
				$options = $newoptions;
				update_option('widget_wpcomstats_visits', $options);
			}
		}
?>
		<label for="widget_wpcomstats_visits-title" style="line-height:25px;display:block;">
			<?php _e('Title:', 'widgets'); ?>
			<input type="text" id="widget_wpcomstats_visits-title" name="widget_wpcomstats_visits-title" value="<?php echo wp_specialchars($options['title'], true); ?>" />
		</label>
		<label for="widget_wpcomstats_visits-days" style="line-height:25px;display:block;">
			<?php _e('Time Interval:', 'widgets'); ?>
			<select id="widget_wpcomstats_visits-days" name="widget_wpcomstats_visits-days">
				<option value="0"<?php echo $options['days'] == 0?' selected="selected"':'';?>>All</option>
				<option value="1"<?php echo $options['days'] == 1?' selected="selected"':'';?>>Today</option>
				<option value="7"<?php echo $options['days'] == 7?' selected="selected"':'';?>>Last Week</option>
				<option value="30"<?php echo $options['days'] == 30?' selected="selected"':'';?>>Last Month</option>
			</select>
		</label>
		<input type="hidden" name="widget_wpcomstats_visits-submit" id="widget_wpcomstats_visits-submit" value="1" />
<?php
	}

	// Register widgets
	register_sidebar_widget(array('Most Visited Posts', 'widgets'),  'widget_wpcomstats_most_visited_posts');
	register_sidebar_widget(array('Blog Visits', 'widgets'),  'widget_wpcomstats_visits');

	// Register widget controls
	register_widget_control(array('Most Visited Posts', 'widgets'), 'widget_wpcomstats_most_visited_posts_control');
	register_widget_control(array('Blog Visits', 'widgets'), 'widget_wpcomstats_visits_control');
	
	function print_credits() {
		echo '<a href="http://vlad.bailescu.ro/?s=search&amp;cx=partner-pub-1471777423767902%3A3w3e6z-pimt&amp;cof=FORID%3A10&amp;ie=UTF-8&amp;q=wp.com+stats+helper&amp;sa=Search#1229" title="Wordpress.com Stats Helper Plugin" style="font-size:.6em">&raquo; wp.com stats helper</a>';
	}
}

// Call widget initialization
add_action('widgets_init', 'wpcomstats_helper_init');

// Watch for plugin deactivation
$plugin_filename= str_replace('\\', '/', preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', __FILE__));
add_action('deactivate_'.$plugin_filename, 'opt_cache_clear');

?>
