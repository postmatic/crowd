=== Crowd Control by Postmatic - Comment moderation decentralized ===
Contributors: postmatic, ronalfy
Tags: moderation, comment moderation, reporting, inappropriate, flagging, flag, comments, report comments, report, inappropriate, spam
Donate link: https://gopostmatic.com
Requires at least: 4.0
Tested up to: 4.4.2
Stable tag: 1.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Comment moderation is a drag. Have your users lend a hand by flagging offensive comments and scrubbing your site clean.

== Description ==

Crowd Control gives your users the ability to report comments as inappropriate with a single click. If a comment gets flagged multiple times it'll be removed from the post and marked as pending moderation. We'll even send you an email to let you know. Now you can still go away on vacation and rest assured the trolls won't overrun your site.

== Installation ==

1. Download and unzip the plugin.
2. Copy the safe-report-comments directory into your plugins folder.
3. Visit your Plugins page and activate the plugin.
4. A new checkbox called "Allow comment flagging" will appear in the Settings->Discussion page.
5. Activate the flag and set the threshold value which will appear on the same page after activation. Set it to whatever you like.

== Screenshots ==

1. Simple settings on the Setings > Discussion screen.
2. A small report box is placed on your comment template allowing visitors to report a comment.
3. A new column is added to the comment moderation screen showing the number of times each comment has been reported. Comments which have been reported more than the allowed number of times (configured by you) are automatically sent to moderation.

== Changelog ==

= 1.1 =

* Nifty new icon.
* Fixed a bug in which all comments were sent to moderation in certain situations.
* The report button is not shown to logged-out users if you have 'Users must be logged in to comment' enabled.
* Fixed errors in the add_flagging_link_comment() method.

= 1.0.1.1 =

* Released 2015-09-29
* Fixed a bug in which reporting a comment would throw a 500 error on certain cache setups.

= 1.0.1 =

* Released 2015-09-29
* Better positioning of Report link.
* Fancy pretty new ajax confirmation of reported links.
* Authors/Contributors/Editors/Admin comments now need 2x the number of reports before being sent to moderation.
* Moderation emails are now sent via Postmatic if it is present.

= 1.0.0 =

* Released 2015-09-22
* Initial release. Based on [Safe Report Comments by Automattic](https://wordpress.org/plugins/safe-report-comments/)... brought up to date, improved, bugs all squashed, cookies added, internationalized, and integrated with Epoch and Postmatic.


