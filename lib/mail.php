<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function mailer_config(): array
{
  static $config = null;
  if ($config !== null) {
    return $config;
  }
  $default = [
    'driver' => 'log',
    'from_email' => 'no-reply@sena.local',
    'from_name' => 'Bolsa de Empleo SENA',
    'reply_to' => 'soporte@sena.local',
    'smtp' => [
      'host' => 'localhost',
      'port' => 25,
      'username' => '',
      'password' => '',
      'encryption' => null,
      'auth' => true,
      'timeout' => 15,
    ],
    'log_path' => __DIR__.'/../storage/logs/mail.log',
  ];
  $configFile = __DIR__.'/../config/mail.php';
  if (is_file($configFile)) {
    $loaded = include $configFile;
    if (is_array($loaded)) {
      $config = array_replace_recursive($default, $loaded);
    } else {
      $config = $default;
    }
  } else {
    $config = $default;
  }
  return $config;
}

function mailer_send(array $message): bool
{
  $config = mailer_config();
  $driver = strtolower((string)($config['driver'] ?? 'log'));
  return match ($driver) {
    'smtp' => mailer_send_via_smtp($message, $config),
    'mail' => mailer_send_via_mail($message, $config),
    default => mailer_log_message($message, $config, false, 'driver=log'),
  };
}

function mailer_send_via_smtp(array $message, array $config): bool
{
  $autoload = __DIR__.'/../vendor/autoload.php';
  if (is_file($autoload)) {
    require_once $autoload;
  }
  if (!class_exists(PHPMailer::class)) {
    mailer_log_message($message, $config, false, 'PHPMailer missing');
    return false;
  }
  $mail = new PHPMailer(true);
  try {
    $smtp = $config['smtp'] ?? [];
    $mail->isSMTP();
    $mail->Host = (string)($smtp['host'] ?? 'localhost');
    $mail->Port = (int)($smtp['port'] ?? 587);
    $mail->SMTPAuth = (bool)($smtp['auth'] ?? true);
    $enc = $smtp['encryption'] ?? 'tls';
    if ($enc && $enc !== 'none') {
      $mail->SMTPSecure = $enc;
    }
    $mail->Username = (string)($smtp['username'] ?? '');
    $mail->Password = (string)($smtp['password'] ?? '');
    $mail->Timeout = (int)($smtp['timeout'] ?? 15);
    $mail->setFrom($message['from_email'] ?? $config['from_email'], $message['from_name'] ?? $config['from_name']);
    $mail->addAddress($message['to'], $message['to_name'] ?? '');
    if (!empty($message['reply_to'] ?? $config['reply_to'])) {
      $mail->addReplyTo($message['reply_to'] ?? $config['reply_to']);
    }
    $mail->CharSet = 'UTF-8';
    $mail->Subject = (string)($message['subject'] ?? '');
    if (!empty($message['html'])) {
      $mail->isHTML(true);
      $mail->Body = $message['html'];
      $mail->AltBody = $message['text'] ?? strip_tags($message['html']);
    } else {
      $mail->Body = $message['text'] ?? '';
    }
    $mail->send();
    mailer_log_message($message, $config, true);
    return true;
  } catch (PHPMailerException $e) {
    mailer_log_message($message, $config, false, $e->getMessage());
  } catch (Throwable $e) {
    mailer_log_message($message, $config, false, $e->getMessage());
  }
  return false;
}

function mailer_send_via_mail(array $message, array $config): bool
{
  $fromEmail = $message['from_email'] ?? $config['from_email'];
  $fromName = $message['from_name'] ?? $config['from_name'];
  $replyTo = $message['reply_to'] ?? $config['reply_to'];
  $subject = (string)($message['subject'] ?? '');
  $html = $message['html'] ?? null;
  $text = $message['text'] ?? strip_tags((string)$html);
  $headers = ['MIME-Version: 1.0'];
  if ($html !== null) {
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $body = $html;
  } else {
    $headers[] = 'Content-type: text/plain; charset=UTF-8';
    $body = $text ?? '';
  }
  $headers[] = 'From: '.$fromName.' <'.$fromEmail.'>';
  if ($replyTo) { $headers[] = 'Reply-To: '.$replyTo; }
  $sent = @mail($message['to'], $subject, $body, implode("\r\n", $headers));
  mailer_log_message($message, $config, $sent, $sent ? '' : 'mail_failed');
  return (bool)$sent;
}

function mailer_log_message(array $message, array $config, bool $sent, string $note = ''): bool
{
  $logPath = $config['log_path'] ?? (__DIR__.'/../storage/logs/mail.log');
  $dir = dirname($logPath);
  if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
  }
  $lines = [];
  $lines[] = '['.date('Y-m-d H:i:s').'] '.($sent ? 'SENT' : 'QUEUED');
  $lines[] = 'To: '.($message['to'] ?? '(desconocido)');
  $lines[] = 'Subject: '.($message['subject'] ?? '(sin asunto)');
  if ($note !== '') { $lines[] = 'Note: '.$note; }
  $lines[] = 'Body: '.substr(strip_tags((string)($message['text'] ?? $message['html'] ?? '')), 0, 400);
  $lines[] = str_repeat('-', 60);
  file_put_contents($logPath, implode(PHP_EOL, $lines).PHP_EOL, FILE_APPEND);
  return true;
}
