=== Presenter ===
Contributors: aaroncampbell
Tags: keynote, powerpoint, presentations, slides, slideshare, slideshow
Requires at least: 4.0
Tested up to: 4.2
Stable tag: 1.0.1

Slideshow presentations made simple on WordPress. Design each slide as you would a post using wysiwyg. Works with most presenter remotes as well!

== Description ==

You'll be able to create presentations in no time using WordPress's familiar built-in toolset. No need for SlideShare, with Presenter you are hosting your own presentations and can share them by simply linking to your site. The presentations are built using <a href="https://github.com/hakimel/reveal.js">Reveal.js</a> by Hakim El Hattab, which means it is extremely extensible, works with most browsers, and even works with presenter remotes.

Professional slideshows right on your WordPress site.

Collaborate on the plugin: <a href="http://github.com/aaroncampbell/presenter">Presenter on GitHub</a>

Brought to you by <a href="http://aarondcampbell.com/" title="WordPress Plugins">Aaron D. Campbell</a>

== Installation ==

1. Use automatic installer to install and active the plugin.

== Frequently Asked Questions ==

= Does this work with a presenter remote? =

Yes! I haven't tested it with every remote of course, but all of them that I
have tested have worked perfectly. My personal favorite is the
<a href="http://amzn.com/B002GHBUTU">Logitech Professional Presenter R800</a>

= Can I make the slideshow look different? =

Absolutely. There are several default themes included, but you can also make
your own. If there is a "presenter" directory in your WordPress theme, Presenter
will look there for additional themes. All you need for a theme is a .css file
with a header like this:
`/** Template Name: My Presenter Template */`

The css file will be included and used in your slideshow.

= I want to put my custom Presenter theme somewhere else. Can I? =
Sure. You can use the `presenter-themes` filter to add your own theme wherever
it is. It is passed an array where the index is the path to the css file and the
value is the name of the theme. Just add your own like this:

`
add_filter( 'presenter-themes', 'add_my_custom_presenter_theme' );

function add_my_custom_presenter_theme( $themes ) {
	$themes['/path/to/my/theme.css'] = 'My amazing theme';
	return $themes;
}
`

== Upgrade Notice ==

= 1.0.0 =
First release

== Changelog ==

= 1.0.1 =
* Fix version number issues

= 1.0.0 =
* Original Version
