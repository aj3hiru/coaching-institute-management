<?php
// course-sitemap.xml — online courses, notes, their categories and free courses (see includes/sitemaps.php)
require __DIR__ . "/includes/sitemaps.php";
edu_sm_serve($pdo, 'course');
