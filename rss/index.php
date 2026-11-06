<?php
// /rss and /rss/ → /feed/ (301), as WordPress
require_once dirname(__DIR__) . '/includes/feed.php';
edu_feed_redirect();
