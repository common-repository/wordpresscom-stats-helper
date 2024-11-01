=== Plugin Name ===
Contributors: bitsofreality
Donate link: http://www.amazon.com/gp/registry/wishlist/184KFWWWFA3VV/
Tags: stats, widget
Requires at least: 2.1
Tested up to: 2.6.2
Stable tag: 0.5.5.3

This plugin helps you retrieve data from wordpress.com stats and put it on your blog.

== Description ==

This plugin helps you retrieve data from wordpress.com stats and put it on your blog.

So far it provides helper functions and widgets to display:

* Most Visited Posts - lists the most visited posts along with a link to the post and the views count
* Blog Visits - a counter of blog or post/page visits

Data can be acquired in the following timeframes:

* All data - all data since you started logging
* Month - data in the last 30 days
* Week - data in the last 7 days
* Day - data collected in 1 day

= Change log =

* 2008.11.15 - Version 0.5.5.3, Added array checks before foreach-es (was causing some ugly PHP warnings for some users).
* 2008.10.05 - Version 0.5.5.2, Added missing default to most visited list: showing both posts and pages.
* 2008.10.05 - Version 0.5.5.1, Small fix for options handling.
* 2008.10.05 - Version 0.5.5, Compatibility fix for WP 2.6.2; Added default options to configuration, optional title truncation and ability to disable promotional link. Code contributed by Michael Tyson <mike@tyson.id.au> http://michael.tyson.id.au/
* 2008.06.12 - Version 0.5.4, Option cache gets cleared of expired elements on each save (was getting too big and causing crashes). Most visited posts title and permalink are no longer retrieved from stats.wordpress.com since they sometimes return wrong data.
* 2008.05.06 - Version 0.5.3, Removed passing by reference in foreach, not supported in PHP4.
* 2008.05.06 - Version 0.5.2, Fixed version numbering.
* 2008.05.06 - Version 0.5.1, Removed try... catch blocks as they're only supported since PHP5.
* 2008.05.03 - Version 0.5, Added possibility to filter most visited items by type (posts, pages or both).
* 2008.04.03 - Version 0.4, Added possibility to display stats in a given timeframe (day, week, month, all time). Changed visit count to allow per post/page stats. Changed visits widget to automatically detect when to display stats for the whole blog and when for a single post/page.
* 2008.03.09 - Version 0.3, Added function and widget for blog visits.
* 2008.02.25 - Version 0.2, Included internal, option-based caching mechanism. Added possibility to hide views count.
* 2008.02.23 - Version 0.1, Most visited posts function and widget.

== Installation ==

This section describes how to install the plugin and get it working.

1. Upload `wpcomstats-helper.php` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress

For most visited posts/pages list:

* Use Most Visited Posts widget in one of you sidebars, or...
* Place `<?php if (function_exists('wpcomstats_most_visited')) { wpcomstats_most_visited($options); } ?>` in your templates, or...
* Place `<?php if (function_exists('wpcomstats_most_visited_posts')) { wpcomstats_most_visited_posts($posts, $before, $after, $show_count, $days); } ?>` in your templates

For blog visits:

* Use Blog Visits widget in one of your sidebars, or...
* Place `<?php if (function_exists('wpcomstats_visits')) wpcomstats_visits($before, $after, $post_id, $days);?>` in your template

Where:

* `$options` - an array which can contain the following key - value pairs:

`posts, before, after, show_count, days` - as bellow

`show` - use `'posts'` to show only posts and `'pages'` to show only pages

* `$posts` - number of posts to display
* `$before, $after` - text/HTML to append before/after each generated text/HTML
* `$show_count` - whether to also diplay a count of each post views
* `$post_id` - the ID of the post/page for which you want view stats (use null to show for all posts/pages)
* `$days` - the number of days for which the stats should be calculated. Valid values are: 0 (all), 1 (today), 7 (week), 30 (month)

== Frequently Asked Questions ==

= Why won't post visits refresh in real time? =

The data from `stats.wordpress.com` is cached for 5 minutes.

== Screenshots ==

1. Most visited posts widget config panel.
2. Blog visits widget config panel.
