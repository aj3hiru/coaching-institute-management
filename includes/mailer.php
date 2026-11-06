<?php
// Email sending with the SMTP details from Admin → Settings → General (Email). Returns true when sent.
// Without SMTP details nothing is sent (messages are still saved in Contact Messages).
require_once __DIR__ . '/settings.php';

function edu_smtp_ready(): bool
{
    return es('general', 'smtp_host') !== '' && es('general', 'smtp_user') !== '' && es('general', 'smtp_pass') !== '';
}

function edu_send_mail(string $to, string $subject, string $html, string $text = '', string $replyTo = '', string $replyName = ''): bool
{
    if (!edu_smtp_ready() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $autoload = ROOT_PATH . '/vendor/autoload.php';
    if (!is_file($autoload)) return false;
    require_once $autoload;
    try {
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string)es('general', 'smtp_host');
        $m->SMTPAuth = true;
        $m->Username = (string)es('general', 'smtp_user');
        $m->Password = (string)es('general', 'smtp_pass');
        $sec = (string)es('general', 'smtp_secure', 'ssl');
        $m->SMTPSecure = $sec === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : ($sec === 'none' ? '' : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS);
        $m->Port = (int)es('general', 'smtp_port', $sec === 'tls' ? 587 : 465);
        $m->CharSet = 'UTF-8';
        $m->setFrom((string)es('general', 'smtp_from', es('general', 'smtp_user')), (string)es('general', 'smtp_from_name', edu_site_name()));
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
