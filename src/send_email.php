<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendEmail(array $args): array {
  $to      = trim($args['to']      ?? '');
  $subject = trim($args['subject'] ?? 'Test');
  $html    = $args['html'] ?? '<p>Test</p>';

  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    return ['ok'=>false, 'error'=>"Destinatario non valido: $to"];
  }

  $smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
  $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
  $smtpUser = trim(getenv('SMTP_USER') ?: '');
  $smtpPass = trim(getenv('SMTP_PASS') ?: '');

  // FROM: usa SMTP_FROM se presente, altrimenti fallback su SMTP_USER
  $from     = trim(getenv('SMTP_FROM') ?: $smtpUser);
  $fromName = trim(getenv('SMTP_FROM_NAME') ?: 'Palestra Athena');

  if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
    return ['ok'=>false, 'error'=>'FROM non valido/assente. Configura SMTP_FROM oppure SMTP_USER.'];
  }

  $mail = new PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->Port       = $smtpPort;
    $mail->SMTPAuth   = true;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS su 587
    $mail->Username   = $smtpUser;   // es: tua.mail@gmail.com
    $mail->Password   = $smtpPass;   // App password a 16 cifre

    $mail->setFrom($from, $fromName);
    // opzionale: Reply-To
    // $mail->addReplyTo($from, $fromName);

    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = strip_tags($html);

    $mail->send();
    return ['ok'=>true];
  } catch (Exception $e) {
    return ['ok'=>false, 'error'=>$mail->ErrorInfo ?: $e->getMessage()];
  }
}
