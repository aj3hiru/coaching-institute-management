<?php
// Email sending, like WordPress: by default through the server's own mail (PHP mail / sendmail), from
// "Site name <noreply@your-domain>". SMTP details saved earlier in Settings are used instead when they exist.
require_once __DIR__ . '/settings.php';

function edu_smtp_ready(): bool
{
    return es('general', 'smtp_host') !== '' && es('general', 'smtp_user') !== '' && es('general', 'smtp_pass') !== '';
}
/** Can the site send email at all (SMTP, or the server's own mail)? */
function edu_mail_ready(): bool
{
    return edu_smtp_ready() || function_exists('mail');
}
/** The address mail comes from when there is no SMTP: noreply@ the site's domain (like wordpress@domain). */
function edu_mail_from(): string
{
    $host = preg_replace('/^www\./i', '', (string)parse_url(SITE_URL, PHP_URL_HOST));
    return 'noreply@' . ($host ?: 'localhost');
}

function edu_send_mail(string $to, string $subject, string $html, string $text = '', string $replyTo = '', string $replyName = ''): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $smtp = edu_smtp_ready();
    $from = $smtp ? (string)es('general', 'smtp_from', es('general', 'smtp_user')) : edu_mail_from();
    $fromName = $smtp ? (string)es('general', 'smtp_from_name', edu_site_name()) : edu_site_name();
    $autoload = ROOT_PATH . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        try {
            $m = new PHPMailer\PHPMailer\PHPMailer(true);
            if ($smtp) {
                $m->isSMTP();
                $m->Host = (string)es('general', 'smtp_host');
                $m->SMTPAuth = true;
                $m->Username = (string)es('general', 'smtp_user');
                $m->Password = (string)es('general', 'smtp_pass');
                $sec = (string)es('general', 'smtp_secure', 'ssl');
                $m->SMTPSecure = $sec === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : ($sec === 'none' ? '' : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS);
                $m->Port = (int)es('general', 'smtp_port', $sec === 'tls' ? 587 : 465);
            } else {
                $m->isMail(); // the server's own mail, like WordPress
            }
            $m->CharSet = 'UTF-8';
            $m->setFrom($from, $fromName);
            $m->addAddress($to);
            if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $m->addReplyTo($replyTo, $replyName);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body = $html;
            $m->AltBody = $text ?: strip_tags($html);
            $m->send();
            return true;
        } catch (Throwable $e) {
            error_log('Mail error: ' . $e->getMessage());
            return false;
        }
    }
    if ($smtp || !function_exists('mail')) return false;
    $b = 'b' . bin2hex(random_bytes(8)); // no PHPMailer: plain mail() with text + HTML
    $enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $h = ['MIME-Version: 1.0', 'From: ' . $enc($fromName) . ' <' . $from . '>', 'Content-Type: multipart/alternative; boundary="' . $b . '"'];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $h[] = 'Reply-To: ' . $replyTo;
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . ($text ?: strip_tags($html)) . "\r\n--$b\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n--$b--";
    return @mail($to, $enc($subject), $body, implode("\r\n", $h), '-f' . $from);
}

/** Contact messages table (created on first use). */
function edu_contact_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL, subject VARCHAR(200) DEFAULT NULL,
        message TEXT NOT NULL, status ENUM('new','read','replied') NOT NULL DEFAULT 'new',
        ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_status (status), KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
