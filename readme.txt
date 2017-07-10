=== Presenter ===
Contributors: aaroncampbell
Tags: keynote, powerpoint, presentations, slides, slideshare, slideshow
Requires at least: 4.8
Tested up to: 4.9
Stable tag: 1.2.0

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

= How do I do background images or videos for my slides? =

On the slide you want to add it to, click the "Show Advanced Slide Setting" link, then click the "Add Data Field" button.

For an image: Set the name of the field to 'background' and put the URL for the image in the value field.

For video: Set the name of the field to 'background-video' and you can add in comma separated URLs to various video sources, such as: //example.com/bearded-dragon-scares-kitten.mp4,//example.com/bearded-dragon-scares-kitten.webm,//example.com/bearded-dragon-scares-kitten.ogv

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

= 1.2.0 =
Better support of WYSIWYG editor - now requires WordPress 4.8+

= 1.1.0 =
Added user interface for slide notes as well as support for full-slide background images and videos!

== Changelog ==

= 1.2.0 =
* Fix advanced link on new slides
* Use the new editor JS in WordPress 4.8 to improve editor on dynamically added slides

= 1.1.1 =
* Upgrade previous slideshows to use new notes UI
* Fix notice when trashing slideshows
* Fix issue that prevented slideshows from being imported with the WordPress importer

= 1.1.0 =
* Added new user interface for slide notes!
* Added support for slide data attributes
* Upgraded reveal.js to 3.5.0

= 1.0.1 =
* Fix version number issues

= 1.0.0 =
* Original Version
