<?php
// Simple endpoint to save base64 images/videos into assets/review/
header('Content-Type: application/json');

$type = $_POST['type'] ?? 'image';
$filename = $_POST['filename'] ?? null;
$subdir = $_POST['subdir'] ?? '';
$data = $_POST['data'] ?? null;

if (!$filename || !$data) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_params']);
  exit;
}

$dir = __DIR__ . '/../assets/review';
if ($subdir) {
  // sanitize subdir: letters, numbers, dash, underscore only
  $subdir = preg_replace('/[^a-zA-Z0-9_\-]/', '', $subdir);
  $dir = $dir . '/' . $subdir;
}
if (!is_dir($dir)) { mkdir($dir, 0777, true); }

// data URL: data:<mime>;base64,<payload>
if (strpos($data, ',') !== false) {
  $parts = explode(',', $data, 2);
  $payload = $parts[1];
} else {
  $payload = $data;
}

$binary = base64_decode($payload);
if ($binary === false) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'decode_failed']);
  exit;
}

$path = $dir . '/' . basename($filename);
$ok = file_put_contents($path, $binary) !== false;
if (!$ok) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'write_failed']);
  exit;
}

echo json_encode(['ok'=>true,'path'=>str_replace(__DIR__.'/../','', $path)]);
?>