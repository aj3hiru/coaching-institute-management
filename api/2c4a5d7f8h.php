<?php
require $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php'; // starts the session

// Helper function — clean inputs, remove header injections, limit length
function clean_field(string $input, int $maxLength = 500): string {
    $s = trim($input);
    $s = str_replace("\0", '', $s);
    $s = preg_replace("/[\r\n]+/", ' ', $s); // prevent header injection
    $s = preg_replace("/\s+/", ' ', $s);
    return mb_substr($s, 0, $maxLength);
}

// Accept only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (empty($_SESSION['cTkn']) || !is_string($_POST['cTkn'] ?? null) || !hash_equals((string)$_SESSION['cTkn'], $_POST['cTkn'])) {
 http_response_code(403); exit('Invalid CSRF token');
}

if (function_exists('edu_throttle') && !edu_throttle('contact:' . edu_client_ip(), 5, 3600)) { http_response_code(429); exit('Too many messages. Please try again later.'); }
foreach (['name', 'email', 'subject', 'message', 'phone'] as $__k) if (isset($_POST[$__k]) && !is_string($_POST[$__k])) { http_response_code(400); exit('Bad request'); }

// Collect & sanitize
$nameRaw    = $_POST['name'] ?? '';
$emailRaw   = $_POST['email'] ?? '';
$subjectRaw = $_POST['subject'] ?? '';
$messageRaw = $_POST['message'] ?? '';

$name    = clean_field($nameRaw, 100);
$subject = clean_field($subjectRaw, 150);

// Validate email strictly
$emailCandidate = trim(str_replace("\0", '', $emailRaw));
$emailCandidate = preg_replace("/[\r\n]+/", '', $emailCandidate);
if (!filter_var($emailCandidate, FILTER_VALIDATE_EMAIL) || mb_strlen($emailCandidate) > 254) {
    http_response_code(400);
    exit('Invalid email address.');
}
$email = $emailCandidate;

// Message cleanup
$message = trim($messageRaw);
$message = str_replace("\0", '', $message);
if (mb_strlen($message) > 10000) {
    http_response_code(400);
    exit('Message too long.');
}
if ($name === '' || $subject === '' || $message === '') {
    http_response_code(400);
    exit('All fields are required.');
}

// Basic rate limiting (1 submission / 20 sec)
$throttleSeconds = 20;
$lastSent = $_SESSION['contact_last_send'] ?? 0;
if (time() - $lastSent < $throttleSeconds) {
    http_response_code(429);
    exit('Too many requests. Please wait a few minutes.');
}

// Escape safely for HTML body
$escName    = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$escEmail   = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$escSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$escMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

$siteName = htmlspecialchars(edu_site_name(), ENT_QUOTES, 'UTF-8');
// Construct message
$htmlBody = <<<HTML
<h3>New Message from Contact Form</h3>
<p><strong>Name:</strong> {$escName}</p>
<p><strong>Email:</strong> {$escEmail}</p>
<p><strong>Subject:</strong> {$escSubject}</p>
<p><strong>Message:</strong><br>{$escMessage}</p>
<hr>
<p style="font-size:12px;color:#666;">Sent from the contact form on {$siteName}</p>
HTML;

$altBody = "Name: {$name}\nEmail: {$email}\nSubject: {$subject}\n\nMessage:\n{$message}";

// Save the message (it shows in Admin → Contact Messages), then email a copy if SMTP is set up.
require_once INCLUDES_PATH . '/mailer.php';
try {
    edu_contact_table($pdo);
    $pdo->prepare("INSERT INTO contact_messages (name, email, subject, message, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$name, $email, $subject, $message, $_SERVER['REMOTE_ADDR'] ?? '', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
} catch (Throwable $e) {
    error_log('Contact form save error: ' . $e->getMessage());
    http_response_code(500);
    exit('Internal Server Error');
}
$to = (string)es('general', 'admin_email', es('general', 'contact_email', CONTACT_EMAIL));
edu_send_mail($to, 'New Contact Form Message: ' . $subject, $htmlBody, $altBody, $email, $name);
$_SESSION['contact_last_send'] = time();
http_response_code(200);
echo 'Message sent successfully!';
