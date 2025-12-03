<?php
// Conexión a base de datos (MySQL) con soporte para distintas fuentes de configuración.
// Prioridad: variables de entorno > config/database.php > valores por defecto (XAMPP/LAMP locales).

// Carga .env de Laravel si existe, para reutilizar credenciales
if (!function_exists('db_load_env_file')) {
  function db_load_env_file(string $path): void
  {
    if (!is_file($path)) { return; }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) { return; }
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) { continue; }
      $parts = explode('=', $line, 2);
      if (count($parts) !== 2) { continue; }
      [$key, $value] = $parts;
      $key = trim($key);
      $value = trim($value, "\"'");
      if ($key === '') { continue; }
      if (getenv($key) === false && !isset($_ENV[$key])) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
      }
    }
  }
}
$projectRoot = dirname(__DIR__);
db_load_env_file($projectRoot.'/laravel-app/.env');
// Mapea variables de Laravel a las usadas aquí
if (!getenv('DB_NAME') && getenv('DB_DATABASE')) { putenv('DB_NAME='.getenv('DB_DATABASE')); $_ENV['DB_NAME'] = getenv('DB_DATABASE'); }
if (!getenv('DB_USER') && getenv('DB_USERNAME')) { putenv('DB_USER='.getenv('DB_USERNAME')); $_ENV['DB_USER'] = getenv('DB_USERNAME'); }
if (!getenv('DB_PASS') && getenv('DB_PASSWORD')) { putenv('DB_PASS='.getenv('DB_PASSWORD')); $_ENV['DB_PASS'] = getenv('DB_PASSWORD'); }
if (!getenv('DB_HOST') && getenv('DB_HOST')) { /* ya se usa DB_HOST */ }
if (!getenv('DB_PORT') && getenv('DB_PORT')) { /* ya se usa DB_PORT */ }

$pdo = null;

try {
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
  if (empty($config['port'])) { $config['port'] = 3306; }
  // Fallback al esquema con datos existentes (ajusta aquí si usas otro nombre)
  if (empty($config['name'])) { $config['name'] = 'mydb'; }
  if ($config['user'] === null || $config['user'] === '') { $config['user'] = 'root'; }
  if ($config['pass'] === null) { $config['pass'] = '1234'; }

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
