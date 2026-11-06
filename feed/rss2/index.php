<?php
// /feed/rss2/ → /feed/ (301), as WordPress
require_once dirname(__DIR__, 2) . '/includes/feed.php';
edu_feed_redirect();
