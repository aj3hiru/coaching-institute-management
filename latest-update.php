<?php // the blog front page moved to /blog
header('Location: /blog' . (($q = (string)($_SERVER['QUERY_STRING'] ?? '')) !== '' ? '?' . $q : ''), true, 301); exit;
