<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

$configFile = __DIR__ . '/resend-config.php';
if (is_file($configFile)) {
    require $configFile;
}

$apiKey = getenv('RESEND_API_KEY') ?: (defined('RESEND_API_KEY') ? RESEND_API_KEY : '');
$toEmail = getenv('CONTACT_TO_EMAIL') ?: (defined('CONTACT_TO_EMAIL') ? CONTACT_TO_EMAIL : '');
$fromEmail = getenv('CONTACT_FROM_EMAIL') ?: (defined('CONTACT_FROM_EMAIL') ? CONTACT_FROM_EMAIL : '');

function redirect_with_status(string $status): never
{
    $redirect = isset($_POST['redirect']) ? trim((string) $_POST['redirect']) : 'contact.html';
    $redirect = preg_replace('/[\r\n].*/', '', $redirect) ?: 'contact.html';
    $separator = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $separator . 'contact_status=' . rawurlencode($status) . '#contact-form-section');
    exit;
}

function field_value(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

if (field_value('website') !== '') {
    redirect_with_status('success');
}

if ($apiKey === '' || $toEmail === '' || $fromEmail === '') {
    redirect_with_status('error');
}

$name = field_value('full_name');
$structure = field_value('organization');
$email = field_value('email');
$phone = field_value('phone');
$subject = field_value('subject');
$period = field_value('event_date');
$venue = field_value('venue');
$message = field_value('message');
$consent = isset($_POST['consent']);
$formName = field_value('form_name') ?: 'Concert 80\'s - Nouvelle demande contact';

if ($name === '' || $email === '' || $subject === '' || $message === '' || !$consent) {
    redirect_with_status('error');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_status('error');
}

$lines = [
    ['Nom et prénom', $name],
    ['Structure', $structure],
    ['E-mail', $email],
    ['Téléphone', $phone],
    ['Sujet', $subject],
    ['Date ou période', $period],
    ['Ville / lieu', $venue],
    ['Message', $message],
];

$htmlParts = [];
foreach ($lines as [$label, $value]) {
    if ($value === '') {
        continue;
    }

    $htmlParts[] = sprintf(
        '<p style="margin:0 0 12px;"><strong>%s :</strong><br>%s</p>',
        htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
    );
}

$payload = [
    'from' => $fromEmail,
    'to' => [$toEmail],
    'reply_to' => [$email],
    'subject' => $formName . ' - ' . $subject,
    'html' => implode("\n", $htmlParts),
];

$jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($jsonPayload === false) {
    redirect_with_status('error');
}

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($response === false || $curlError !== '' || $statusCode < 200 || $statusCode >= 300) {
    error_log('Resend send failed: ' . ($curlError !== '' ? $curlError : (string) $response));
    redirect_with_status('error');
}

redirect_with_status('success');