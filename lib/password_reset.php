<?php
declare(strict_types=1);

require_once __DIR__.'/mail.php';

function pr_mask_email(string $email): string
{
  $email = trim($email);
  if ($email === '' || strpos($email, '@') === false) {
    return 'ana***@ejemplo.com';
  }
  [$user, $domain] = explode('@', $email, 2);
  $visible = strlen($user) <= 3 ? 1 : 3;
  return substr($user, 0, $visible).str_repeat('*', max(3, strlen($user) - $visible)).'@'.$domain;
}

function pr_ensure_password_reset_table(PDO $pdo): void
{
  static $created = false;
  if ($created) { return; }
  $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    user_type ENUM('persona','empresa') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    requested_ip VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE KEY uq_token (token_hash),
    KEY idx_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $created = true;
}

function pr_prune_password_resets(PDO $pdo): void
{
  pr_ensure_password_reset_table($pdo);
  $pdo->exec("DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < (NOW() - INTERVAL 7 DAY)");
}

function pr_find_account_by_email(PDO $pdo, string $email): ?array
{
  $email = strtolower(trim($email));
  if ($email === '') { return null; }
  pr_ensure_password_reset_table($pdo);
  $stmt = $pdo->prepare('SELECT email, CONCAT(nombres," ",apellidos) AS nombre FROM candidatos WHERE email = ? LIMIT 1');
  $stmt->execute([$email]);
  if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    return ['email' => $row['email'], 'user_type' => 'persona', 'nombre' => trim($row['nombre']) ?: $row['email']];
  }
  $stmt = $pdo->prepare('SELECT email, nombre_contacto AS nombre FROM empresa_cuentas WHERE email = ? LIMIT 1');
  $stmt->execute([$email]);
  if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    return ['email' => $row['email'], 'user_type' => 'empresa', 'nombre' => trim($row['nombre']) ?: $row['email']];
  }
  return null;
}

function pr_create_reset_token(PDO $pdo, string $email, string $userType, ?string $ip, ?string $userAgent, int $minutes = 60): ?array
{
  pr_ensure_password_reset_table($pdo);
  $token = bin2hex(random_bytes(32));
  $hash = hash('sha256', $token);
  $expires = (new DateTimeImmutable('+'.$minutes.' minutes'))->format('Y-m-d H:i:s');
  $stmt = $pdo->prepare('INSERT INTO password_resets (email,user_type,token_hash,requested_ip,user_agent,expires_at) VALUES (?,?,?,?,?,?)');
  $saved = $stmt->execute([$email, $userType, $hash, $ip, $userAgent, $expires]);
  if ($saved) {
    return ['token' => $token, 'expires_at' => $expires];
  }
  return null;
}

function pr_get_reset_by_token(PDO $pdo, string $token): ?array
{
  pr_ensure_password_reset_table($pdo);
  $hash = hash('sha256', trim($token));
  $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token_hash = ? LIMIT 1');
  $stmt->execute([$hash]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) { return null; }
  if ($row['used_at'] !== null) { return null; }
  if (strtotime((string)$row['expires_at']) <= time()) { return null; }
  return $row;
}

function pr_mark_reset_used(PDO $pdo, int $id): void
{
  pr_ensure_password_reset_table($pdo);
  $stmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
  $stmt->execute([$id]);
}

function pr_build_reset_url(string $token): string
{
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

  $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
  $script = str_replace('\\', '/', $script); // normaliza para entornos Windows
  $base = rtrim(dirname($script), '/');
  if ($base === '' || $base === '.') {
    $base = '';
  }
  $path = $base === '' ? '/' : $base.'/';

  $url = $scheme.'://'.$host.$path.'index.php?view=recuperar_crear&token='.urlencode($token);
  return str_replace('\\', '/', $url);
}

function pr_send_reset_email(string $to, string $resetUrl, string $expiresAt, string $userType): bool
{
  $subject = 'Recupera tu contraseña - Bolsa de Empleo SENA';
  $body = '<p>Hola,</p>'
    .'<p>Recibimos una solicitud para restablecer la contraseña de tu cuenta ('.$userType.').</p>'
    .'<p>Puedes crear una nueva contraseña ingresando al siguiente enlace antes de '.$expiresAt.':</p>'
    .'<p><a href="'.$resetUrl.'">'.$resetUrl.'</a></p>'
    .'<p>Si no solicitaste este cambio, puedes ignorar este mensaje.</p>';
  return mailer_send([
    'to' => $to,
    'subject' => $subject,
    'html' => $body,
    'text' => strip_tags($body),
  ]);
}

function pr_log_reset_link(string $email, string $resetUrl): void
{
  $log = __DIR__.'/../storage/password_resets.log';
  $dir = dirname($log);
  if (!is_dir($dir)) { mkdir($dir, 0775, true); }
  $line = '['.date('Y-m-d H:i:s').'] '.$email.' => '.$resetUrl.PHP_EOL;
  file_put_contents($log, $line, FILE_APPEND);
}
