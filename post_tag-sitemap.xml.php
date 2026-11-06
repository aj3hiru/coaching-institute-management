<?php
// post_tag-sitemap.xml — see includes/sitemaps.php
require __DIR__ . "/includes/sitemaps.php";
edu_sm_serve($pdo, 'post_tag');
