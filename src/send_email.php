<?php
use PHPMailer\PHPMailer\PHPMailer;

function send_email($to, string $subject, string $html, ?string $text = null, array $bcc = []): array {
  $host   = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
  $port   = (int)(getenv('SMTP_PORT') ?: 587);
  $secure = strtolower(getenv('SMTP_SECURE') ?: 'tls'); // tls | ssl
  $user   = getenv('SMTP_USER') ?: '';
  $pass   = getenv('SMTP_PASS') ?: '';
  $from   = getenv('SMTP_FROM') ?: $user;
  $fromNm = getenv('SMTP_FROM_NAME') ?: 'Notifier';

  $mail = new PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->Port       = $port;
    $mail->SMTPSecure = ($secure === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($from, $fromNm);

    // $to può essere string o array
    if (is_array($to)) {
      foreach ($to as $addr) if ($addr) $mail->addAddress(trim($addr));
    } else {
      if ($to) $mail->addAddress(trim($to));
    }
    foreach ($bcc as $addr) if ($addr) $mail->addBCC(trim($addr));

    // Reply-To opzionale
    $rt = getenv('REPLY_TO_EMAIL') ?: '';
    if ($rt) $mail->addReplyTo($rt, getenv('REPLY_TO_NAME') ?: $fromNm);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = $text ?: strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));

    $mail->send();
    return ['ok' => true];
  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}
