<?php
// Categories helpers — the default category (like WordPress "Uncategorized"): posts saved without a category go
// there, and posts of a deleted category move there. It can be renamed but never deleted.
require_once __DIR__ . '/settings.php';

function edu_default_category(?PDO $pdo = null): int
{
    static $id = null;
    if ($id !== null) return $id;
    $pdo = $pdo ?: edu_db();
    $id = (int)es('content', 'default_category', 0);
    if ($id) {
        $st = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
        $st->execute([$id]);
        if ($st->fetchColumn()) return $id;
    }
    $st = $pdo->prepare("SELECT id FROM categories WHERE slug = 'general'");
    $st->execute();
    $id = (int)$st->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO categories (name, slug) VALUES ('General', 'general')")->execute();
        $id = (int)$pdo->lastInsertId();
    }
    edu_settings_save('content', ['default_category' => $id]);
    return $id;
}
