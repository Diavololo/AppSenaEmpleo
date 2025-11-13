<?php
/**
 * Configuración SMTP para Gmail con los valores por defecto del proyecto.
 * Puedes sobreescribir cualquiera de estos parámetros vía variables de entorno.
 */
$env = static function (string $name, $default) {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
};

$gmailEmail = 'jyaasociated@gmail.com';
$gmailPassword = 'xnxthfelbmpyhqre'; // contraseña de aplicaciones (sin espacios)

return [
  'driver' => $env('MAIL_DRIVER', 'smtp'),
  'from_email' => $env('MAIL_FROM_ADDRESS', $gmailEmail),
  'from_name' => $env('MAIL_FROM_NAME', 'Bolsa de Empleo SENA'),
  'reply_to' => $env('MAIL_REPLY_TO', $gmailEmail),
  'smtp' => [
    'host' => $env('MAIL_HOST', 'smtp.gmail.com'),
    'port' => (int)$env('MAIL_PORT', 587),
    'username' => $env('MAIL_USERNAME', $gmailEmail),
    'password' => $env('MAIL_PASSWORD', $gmailPassword),
    'encryption' => $env('MAIL_ENCRYPTION', 'tls'),
    'auth' => $env('MAIL_SMTP_AUTH', '1') !== '0',
    'timeout' => (int)$env('MAIL_TIMEOUT', 30),
  ],
  'log_path' => __DIR__.'/../storage/logs/mail.log',
];
