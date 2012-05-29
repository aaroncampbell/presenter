=== Efficient Related Posts ===
Contributors: aaroncampbell
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=9996714
Tags: related posts, posts, related, seo
Requires at least: 2.8
Tested up to: 3.3
Stable tag: 0.3.8

A related posts plugin that works quickly even with thousands of posts and tags.  Can be added automatically to the end of posts. Requires PHP5.

== Description ==

There is a <a href="http://xavisys.com/problem-related-post-plugins/">problem
with related posts plugins</a>, and Efficient Related Posts is fixing that by
approaching the problem from a different direction and offering a very different
solution.

Basically, current related post plugins build the list of related posts on the
fly when the user needs to view it.  Since blogs tend to be viewed far more
often than they are updated (often hundreds of times more often), these queries
are run way more times than they need to be.  This not only wastes CPU cycles,
but if the queries are slow (which they will be if you have 1000s of posts and
tags) then the user gets a poor experience from slow page loads.

Efficient Related Posts moves all this effort into the admin section, finding
related posts when a post is saved rather than when the user views it.  The
advantage is that if the query is slow it happens less often and the post writer
is the one that waits rather than the user (which I think is WAY better).

There are limitations.  For example, since the related posts are stored as post
meta data, we only store a certain number of them (10 by default, but you can
set it to whatever you want).  This means that if you decide you need to display
more than 10, you need to have the plugin re-process all posts.  I generally
display up to 5 related posts, but store 10 just in case I decide to display
more in some areas.  Also, since the related posts are calculated when a post is
saved, manually adding a tag through the database will have no effect on the
related posts, although I recommend not doing that anyway.

Requires PHP5.

== Installation ==

1. Verify that you have PHP5, which is required for this plugin.
1. Upload the whole `efficient-related-posts` directory to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Configure related posts by going to Settings -> Related Posts

== Frequently Asked Questions ==

= How can I add a list of related posts to my posts? =

You can configure Efficient Related Posts to add related posts automatically in
Settings -> Related Posts.  Alternatively you can use the shortcode
[relatedPosts] or the you can use the 'erp-show-related-posts' action or
'erp-get-related-posts' filter in your theme files.

= How exactly do you use the [[relatedPosts]] shortcode? =

To use the default settings (from Settings -> Related Posts) you just need to
add `[relatedPosts]` to your post or page where you want to list to be.  You can
also add some attributes to it such as num_to_display (Number of related posts
to display), no_rp_text (Text to display if there are no related posts), and
title (Title for related posts list, empty for none) like this:

* `[relatedPosts title="Most Related Post" num_to_display="1"]`
* `[relatedPosts num_to_display="1" no_rp_text="No Related Posts Found"]`
* `[relatedPosts title="Try these related posts:" num_to_display="3" no_rp_text="No Related Posts Found"]`

= How do I add this to my theme? =

You can use the 'erp-show-related-posts' action or 'erp-get-related-posts'
filter to display a list of related posts in your theme.  They need to be used
in "the loop" and the only difference is that the 'erp-get-related-posts' filter
returns the list and the 'erp-show-related-posts' action echos the list.  You
can also pass an associative array of arguments to it such as num_to_display
(Number of related posts to display), no_rp_text (Text to display if there are
no related posts), and title (Title for related posts list, empty for none) like
this:

* `<?php do_action('erp-show-related-posts', array('title'=>'Most Related Post', 'num_to_display'=>1)); ?>`
* `<?php echo apply_filters('erp-get-related-posts', array('num_to_display'=>1, 'no_rp_text'=>'No Related Posts Found')); ?>`
* `<?php do_action('erp-show-related-posts', array('title'=>'Most Related Posts', 'num_to_display'=>3, 'no_rp_text'=>'No Related Posts Found')); ?>`

= How do the theme helper functions work? =

The theme helper functions still exist, but the new actions and filters
mentioned above are preferred.  Hopefully the helper functions will be removed
in the future, so PLEASE don't use them.

= If it calculates related posts when a post is saved, won't a post only be related to older posts? =

No, Efficient Related Posts finds all the posts related to the one being saved,
and if the current post is more closely related to one of those posts than the
least related post that is currently stored, it re-processes that post.  Simple
right?  Well, maybe it's not so simple, but rest assured that your posts can and
will show the posts they are most related to regardless of post date.

= What metrics are used? =

Posts are considered related based on tags.  This may be extended in the future,
but I wanted to keep the queries as clean as possible.

== Upgrade Notice ==

= 0.3.7 =
Adds support for post images
Fixes notices that are thrown during activation and when processing all posts
Uses the new WordPress function for escaping data

== Changelog ==

= 0.4.0 =
* Add ability to limit related posts to certain post types

= 0.3.8 =
* Fixed the notices when you activate the plugin.  For real this time!

= 0.3.7 =
* Fix notices that are thrown for undefined index
* Add a new 'post_image' element to each related post if you is specified
* Use newer esc_* functionsUpdate to the newest version of the Xavisys WordPress Plugin Framework
* Upgrade Xavisys Plugin Framework

= 0.3.6 =
* Update to the newest version of the Xavisys WordPress Plugin Framework
* Fixes small display error on settings page for WP 3.0
* Add 'erp-show-related-posts' action
* Add 'erp-get-related-posts' filter
* Deprecate wp_related_posts()
* Deprecate wp_get_related_posts()

= 0.3.5 =
* Update to the newest version of the Xavisys WordPress Plugin Framework
* Fixed an issue with the auto insert

= 0.3.4 =
* Updated the plugin to use the new Xavisys Plugin Framework
* Added a Xavisys News feed to the dashboard (can be hidden using Screen Options)
* Original prep to internationalize the plugin (hopefully completely translatable in the next version)

= 0.3.3 =
* Added links to the support forums
* Updated links to link to new plugin homepage location: http://xavisys.com/wordpress-plugins/efficient-related-posts/
* Updated the system that shows changes when you're prompted to update

= 0.3.2 =
* Added the `[relatedPosts]` shortcode as another way to add a list of related posts to a post or page

= 0.3.1 =
* Added some more options for where to display the related posts.
* Added ability to give more information about updates on the plugins page.

= 0.3.0 =
* Completely reworked how related posts are stored.  Now we store the title and permalink along with the ID, which eliminates the need to to query for each related post.
* Added an action to fix all permalinks if the structure is updated.

= 0.2.7 =
* Replaced `esc_html` with `wp_specialchars` for those still on 2.7.x

= 0.2.6 =
* Categories to ignore are now chosen using checkboxes!  Much better than finding category IDs and making a comma separated list of them
* Moved Changelog into readme file so you can see it on wordpress.org

= 0.2.5 =
* Fixed warning caused by array_walk returning a non-array
* Add link to settings page.

= 0.2.4 =
* Fixed plugin URI

= 0.2.3 =
* Released via WordPress.org

= 0.2.2 =
* Fixed issue with title not displaying
* Renamed in anticipation of adding to WordPress.org

= 0.2.1 =
* When spidering though related posts, limit the posts that are checked

= 0.2.0 =
* First run of processing posts in chunks

= 0.1.4 =
* Fixed array_slice error that showed up when there were no related posts
* Fixed the issue with the "No Related Posts" text not showing

= 0.1.3 =
* Formatted Admin page warning correctly

= 0.1.2 =
* Added all copy and made it all translatable for future application

= 0.1.1 =
* MySQL query optimizations to reduce processing time

= 0.1.0 =
* Added all settings to admin page
* Added helper functions for displaying
* Added ability to add related posts to RSS
* Added ability to ignore categories from matches
* Added ability to automatically add to posts
* Added ability to specify title
* Added ability to specify text to display if no related posts exist

= 0.0.4 =
* Added admin page to process posts - still needs serious cleanup

= 0.0.3 =
* Processes all posts

= 0.0.2 =
* Processes Post now

= 0.0.1 =
* Original Version
