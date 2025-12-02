<?php
declare(strict_types=1);

require_once __DIR__.'/MatchService.php';

if (!function_exists('ms_normalize_vac')) {
  /**
   * Normaliza el array de vacante para el cálculo de match.
   * @param array<string,mixed> $vac
   * @return array<string,mixed>
   */
  function ms_normalize_vac(array $vac): array
  {
    return [
      'id' => isset($vac['id']) ? (int)$vac['id'] : (int)($vac['vacante_id'] ?? 0),
      'vacante_id' => isset($vac['vacante_id']) ? (int)$vac['vacante_id'] : (isset($vac['id']) ? (int)$vac['id'] : 0),
      'titulo' => $vac['titulo'] ?? '',
      'descripcion' => $vac['descripcion'] ?? '',
      'requisitos' => $vac['requisitos'] ?? '',
      'etiquetas' => $vac['etiquetas'] ?? '',
      'ciudad' => $vac['ciudad'] ?? '',
      'area_nombre' => $vac['area_nombre'] ?? ($vac['area'] ?? ''),
      'nivel_nombre' => $vac['nivel_nombre'] ?? ($vac['nivel'] ?? ''),
      'modalidad_nombre' => $vac['modalidad_nombre'] ?? ($vac['modalidad'] ?? ''),
    ];
  }
}

if (!function_exists('ms_score')) {
  /**
   * Cálculo único de match (cachea por candidato+vacante).
   */
  function ms_score(PDO $pdo, array $vacante, string $candidateEmail): float
  {
    static $cache = [];
    $vac = ms_normalize_vac($vacante);
    $vacId = $vac['vacante_id'] ?: $vac['id'];
    $key = $candidateEmail.'|'.(string)$vacId;
    if (isset($cache[$key])) {
      return $cache[$key];
    }
    $result = MatchService::scoreFor($pdo, $vac, $candidateEmail);
    $cache[$key] = (float)$result['score'];
    return $cache[$key];
  }
}

