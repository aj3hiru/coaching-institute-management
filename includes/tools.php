<?php
// Shared helpers for Admin → Tools (Import & Export, Backup & Restore): private storage outside the public folder,
// uploads sent in small pieces (the server only accepts small single uploads), and a database dump / restore.
require_once __DIR__ . '/settings.php';

/** A folder next to public_html (not reachable from the web). */
function edu_private_dir(string $sub): string
{
    $dir = dirname(ROOT_PATH) . '/edumint24-private/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

/**
 * Receives one piece of a file: POST upload_id, index, total, name + file "chunk". When the last piece arrives the
 * pieces are joined and the full path is returned; otherwise null.
 */
function edu_chunk_receive(): ?array
{
    $id = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['upload_id'] ?? ''));
    $index = (int)($_POST['index'] ?? -1);
    $total = (int)($_POST['total'] ?? 0);
    $name = basename((string)($_POST['name'] ?? 'upload.zip'));
    if ($id === '' || strlen($id) > 40 || $index < 0 || $total < 1 || $total > 5000 || $index >= $total) throw new RuntimeException('Bad upload request.');
    if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('A piece of the file did not arrive. Please try again.');
    $dir = edu_private_dir('uploads/' . $id);
    move_uploaded_file($_FILES['chunk']['tmp_name'], $dir . '/' . sprintf('%05d', $index));
    if (count(glob($dir . '/[0-9][0-9][0-9][0-9][0-9]') ?: []) < $total) return null;
    $final = edu_private_dir('incoming') . '/' . $id . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    $out = fopen($final, 'wb');
    for ($i = 0; $i < $total; $i++) {
        $part = $dir . '/' . sprintf('%05d', $i);
        $in = fopen($part, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        @unlink($part);
    }
    fclose($out);
    @rmdir($dir);
    return ['path' => $final, 'name' => $name, 'size' => filesize($final)];
}

/** Writes a full SQL dump of every table to $file (structure + data). */
function edu_sql_dump(PDO $pdo, string $file): int
{
    $fh = fopen($file, 'wb');
    fwrite($fh, "-- " . (defined('SITE_URL') ? parse_url(SITE_URL, PHP_URL_HOST) : 'site') . " backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "\nDROP TABLE IF EXISTS `$t`;\n$create;\n");
        $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
        $batch = [];
        while ($row = $st->fetch()) {
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
            if (count($batch) >= 200) { fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        if ($batch) fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return count($tables);
}

/** Runs an SQL dump made by edu_sql_dump() (statements end with ";\n"). */
function edu_sql_restore(PDO $pdo, string $file): int
{
    $fh = fopen($file, 'rb');
    $buf = '';
    $n = 0;
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    while (($line = fgets($fh)) !== false) {
        if ($buf === '' && (str_starts_with($line, '--') || trim($line) === '')) continue;
        $buf .= $line;
        if (preg_match('/;\s*$/', $line) && !edu_sql_open_quote($buf)) {
            $pdo->exec($buf);
            $buf = '';
            $n++;
        }
    }
    fclose($fh);
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    return $n;
}

/** True when $sql ends inside an unfinished quoted string (so a ";" at the line end is part of the data). */
function edu_sql_open_quote(string $sql): bool
{
    $open = false;
    for ($i = 0, $l = strlen($sql); $i < $l; $i++) {
        $c = $sql[$i];
        if ($c === '\\') { $i++; continue; }
        if ($c === "'") $open = !$open;
    }
    return $open;
}

/** Adds a folder to a zip under $prefix. */
function edu_zip_dir(ZipArchive $zip, string $dir, string $prefix): int
{
    $n = 0;
    if (!is_dir($dir)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $zip->addFile($f->getPathname(), $prefix . '/' . substr($f->getPathname(), strlen($dir) + 1));
        $n++;
    }
    return $n;
}

/** Client-side helper for uploading a file in pieces (printed once on a page). */
function edu_chunk_js(): string
{
    return <<<'JS'
<script>
window.eduChunkUpload = function (url, file, extra, onProgress) {
  var size = 1024 * 1024, total = Math.max(1, Math.ceil(file.size / size)), id = Date.now().toString(36) + Math.random().toString(36).slice(2, 8), i = 0;
  return new Promise(function (resolve, reject) {
    (function next() {
      var fd = new FormData();
      Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
      fd.append('upload_id', id); fd.append('index', i); fd.append('total', total); fd.append('name', file.name);
      fd.append('chunk', file.slice(i * size, (i + 1) * size), 'chunk');
      fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.error) return reject(new Error(d.error));
        i++; onProgress && onProgress(Math.round(i / total * 100));
        if (i < total) next(); else resolve(d);
      }).catch(reject);
    })();
  });
};
</script>
JS;
}
