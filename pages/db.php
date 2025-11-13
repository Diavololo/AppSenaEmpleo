<?php
// Conexión a base de datos (MySQL) con soporte para distintas fuentes de configuración.
// Prioridad: variables de entorno > config/database.php > valores por defecto (XAMPP/LAMP locales).

$pdo = null;

try {
  $projectRoot = dirname(__DIR__);
  $config = [
    'host' => getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? null),
    'port' => getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? null),
    'name' => getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null),
    'user' => getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? null),
    'pass' => getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? null),
  ];

  $configFile = $projectRoot.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'database.php';
  if (is_file($configFile)) {
    $fileConfig = include $configFile;
    if (is_array($fileConfig)) {
      foreach (['host', 'port', 'name', 'user', 'pass'] as $key) {
        if (($config[$key] === null || $config[$key] === '') && isset($fileConfig[$key])) {
          $config[$key] = $fileConfig[$key];
        }
      }
    }
  }

  if (empty($config['host'])) { $config['host'] = '127.0.0.1'; }
  if (empty($config['name'])) { $config['name'] = 'sena_bolsa_empleo'; }
  if ($config['user'] === null) { $config['user'] = 'root'; }
  if ($config['pass'] === null) { $config['pass'] = ''; }

  if ($config['host'] && $config['name'] && $config['user'] !== null) {
    $dsn = sprintf(
      'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
      $config['host'],
      $config['port'] ? 'port='.$config['port'].';' : '',
      $config['name']
    );

    $pdo = new PDO($dsn, (string)$config['user'], (string)$config['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
} catch (Throwable $e) {
  error_log('[DB] Conexión fallida: '.$e->getMessage());
}
