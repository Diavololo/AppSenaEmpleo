<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php?view=login');
  exit;
}

$redirect = 'index.php?view=login';

$setFlash = static function (string $message, string $type = 'error'): void {
  $_SESSION['flash'] = ['type' => $type, 'message' => $message];
};

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
  $setFlash('Por seguridad, vuelve a intentar iniciar sesión.');
  header('Location: '.$redirect);
  exit;
}

$userType = $_POST['user_type'] ?? '';
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
  $_SESSION['login_old'] = ['type' => $userType, 'email' => $email];
  $setFlash('Ingresa tu correo y contraseña.');
  header('Location: '.$redirect);
  exit;
}

if (!in_array($userType, ['persona', 'empresa'], true)) {
  $_SESSION['login_old'] = ['type' => 'persona', 'email' => $email];
  $setFlash('Selecciona el tipo de usuario correcto.');
  header('Location: '.$redirect);
  exit;
}

require_once __DIR__.'/../pages/db.php';

if (!($pdo instanceof PDO)) {
  $_SESSION['login_old'] = ['type' => $userType, 'email' => $email];
  $setFlash('No hay conexión con la base de datos. Intenta más tarde.');
  header('Location: '.$redirect);
  exit;
}

try {
  if ($userType === 'persona') {
    $stmt = $pdo->prepare('SELECT email, nombres, apellidos, password_hash FROM candidatos WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
      $_SESSION['login_old'] = ['type' => 'persona', 'email' => $email];
      $setFlash('Correo o contraseña incorrectos.');
      header('Location: '.$redirect);
      exit;
    }

    $_SESSION['user'] = [
      'type' => 'persona',
      'email' => $row['email'],
      'nombre' => $row['nombres'],
      'apellidos' => $row['apellidos'],
      'display_name' => trim(($row['nombres'] ?? '').' '.($row['apellidos'] ?? '')),
    ];
  } else {
    $stmt = $pdo->prepare(
      'SELECT ec.email,
              ec.password_hash,
              ec.estado            AS cuenta_estado,
              ec.rol               AS cuenta_rol,
              ec.empresa_id,
              ec.nombre_contacto,
              e.razon_social,
              e.estado             AS empresa_estado,
              e.ciudad,
              e.sector_id,
              s.nombre             AS sector_nombre
       FROM empresa_cuentas ec
       INNER JOIN empresas e      ON e.id = ec.empresa_id
       LEFT JOIN sectores s       ON s.id = e.sector_id
       WHERE ec.email = ?
       LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
      $_SESSION['login_old'] = ['type' => 'empresa', 'email' => $email];
      $setFlash('Correo o contraseña incorrectos.');
      header('Location: '.$redirect);
      exit;
    }

    if (!in_array($row['cuenta_estado'], ['activo', 'invitado'], true)) {
      $_SESSION['login_old'] = ['type' => 'empresa', 'email' => $email];
      $setFlash('Tu cuenta de empresa no está activa. Comunícate con el administrador.');
      header('Location: '.$redirect);
      exit;
    }

    if ($row['empresa_estado'] === 'bloqueada') {
      $_SESSION['login_old'] = ['type' => 'empresa', 'email' => $email];
      $setFlash('La empresa está bloqueada. No es posible iniciar sesión.');
      header('Location: '.$redirect);
      exit;
    }

    $sedeParts = [];
    if (!empty($row['ciudad'])) {
      $sedeParts[] = $row['ciudad'];
    }
    if (!empty($row['sector_nombre'])) {
      $sedeParts[] = $row['sector_nombre'];
    }

    $_SESSION['user'] = [
      'type' => 'empresa',
      'email' => $row['email'],
      'nombre' => $row['nombre_contacto'],
      'empresa_id' => (int)$row['empresa_id'],
      'empresa' => $row['razon_social'],
      'display_name' => (trim((string)$row['nombre_contacto']) !== '') ? $row['nombre_contacto'] : $row['razon_social'],
      'rol' => $row['cuenta_rol'],
    ];
    $_SESSION['empresa_email'] = $row['email'];
    $_SESSION['empresa_nombre'] = $row['razon_social'];
    $_SESSION['empresa_sede'] = $sedeParts ? implode(' · ', $sedeParts) : $row['razon_social'];

    $update = $pdo->prepare('UPDATE empresa_cuentas SET ultimo_acceso = NOW() WHERE email = ?');
    $update->execute([$row['email']]);
  }

  unset($_SESSION['login_old']);
  $setFlash('Inicio de sesión correcto.', 'success');

  if (($_SESSION['user']['type'] ?? '') === 'empresa') {
    header('Location: index.php?view=perfil_empresa');
  } else {
    header('Location: index.php?view=dashboard');
  }
  exit;
} catch (Throwable $e) {
  $_SESSION['login_old'] = ['type' => $userType, 'email' => $email];
  error_log('[LOGIN] '.$e->getMessage());
  $setFlash('Ocurrió un error al iniciar sesión. Intenta de nuevo.');
  header('Location: '.$redirect);
  exit;
}




