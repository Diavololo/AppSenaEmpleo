<?php
declare(strict_types=1);

$matchOpenAIClientFile = __DIR__.'/OpenAIClient.php';
if (is_file($matchOpenAIClientFile)) {
    require_once $matchOpenAIClientFile;
}

final class MatchService
{
    private static bool $clientInitialized = false;
    private static ?OpenAIClient $client = null;
    private static bool $encodingHelperLoaded = false;

    public static function getClient(): ?OpenAIClient
    {
        if (self::$clientInitialized) {
            return self::$client;
        }
        self::$clientInitialized = true;

        if (!class_exists('OpenAIClient')) {
            return null;
        }
        $apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
        if (trim((string)$apiKey) === '') {
            return null;
        }
        $base = getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1';
        $model = getenv('OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small';
        try {
            self::$client = new OpenAIClient($apiKey, $base, $model);
        } catch (Throwable $e) {
            self::$client = null;
        }

        return self::$client;
    }

    private static function normalize(string $text): string
    {
        if (!self::$encodingHelperLoaded) {
            $helper = __DIR__.'/EncodingHelper.php';
            if (is_file($helper)) { require_once $helper; }
            self::$encodingHelperLoaded = true;
        }
        if (function_exists('fix_mojibake')) {
            return fix_mojibake($text);
        }
        $value = trim($text);
        if ($value === '') { return ''; }
        // Corrige patrones comunes de mojibake (Ã¡, Ã±, �)
        if (preg_match('/Ã.|Â.|â€|�/u', $value)) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            if ($converted !== false) { $value = $converted; }
        }
        return $value;
    }

    /**
     * Limpia listas de skills o etiquetas en minúscula y sin ruido.
     * @param array<int,string>|string|null $skills
     * @return string[]
     */
    public static function cleanList($skills): array
    {
        if (is_string($skills)) {
            $skills = preg_split('/[,;|]+/', $skills) ?: [];
        }
        if (!is_array($skills)) {
            return [];
        }

        $out = [];
        foreach ($skills as $skill) {
            $s = self::normalize((string)$skill);
            if ($s === '') { continue; }
            $s = preg_replace('/[·•●▪◦]+/u', ' ', $s);
            $s = preg_replace('/\d+\s*(anos|anios|años)?/iu', '', $s);
            $s = preg_replace('/\s+anos?|\s+anios?|\s+años?/iu', '', $s);
            $s = preg_replace('/[^a-zA-Z0-9áéíóúñüÁÉÍÓÚÑÜ]+/u', ' ', $s);
            $s = trim(preg_replace('/\s+/', ' ', (string)$s));
            if ($s === '') { continue; }
            $out[] = mb_strtolower($s, 'UTF-8');
        }

        return array_values(array_unique($out));
    }

    public static function norm(array $vector): float
    {
        $sum = 0.0;
        foreach ($vector as $v) {
            $sum += $v * $v;
        }
        return sqrt($sum);
    }

    public static function cosine(array $a, float $normA, array $b, float $normB): float
    {
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }
        $len = min(count($a), count($b));
        $dot = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
        }
        return $dot / ($normA * $normB);
    }

    public static function profileText(array $profile, string $email): string
    {
        $parts = array_filter([
            'Email: '.$email,
            'Rol deseado: '.($profile['role'] ?? ''),
            'Nivel: '.($profile['level'] ?? ''),
            'Ciudad: '.($profile['city'] ?? ''),
            'Modalidad: '.($profile['modalidad'] ?? ''),
            'Disponibilidad: '.($profile['disponibilidad'] ?? ''),
            'Resumen: '.($profile['summary'] ?? ''),
            $profile['skills'] ? 'Habilidades: '.implode(', ', (array)$profile['skills']) : null,
        ]);
        return implode("\n", $parts);
    }

    public static function candidateProfile(PDO $pdo, string $email, bool $withEmbedding = true): array
    {
        $profile = [
            'role' => '',
            'city' => '',
            'modalidad' => null,
            'disponibilidad' => null,
            'level' => null,
            'years' => null,
            'summary' => null,
            'skills' => [],
            'skills_clean' => [],
            'vector' => null,
            'norm' => 0.0,
            'error' => null,
        ];

        try {
          $stmt = $pdo->prepare(
              'SELECT c.ciudad,
                      cp.rol_deseado,
                      cp.anios_experiencia,
                      cp.habilidades,
                      cd.perfil AS resumen,
                      n.nombre AS nivel_nombre,
                      m.nombre AS modalidad_nombre,
                      d.nombre AS disponibilidad_nombre
               FROM candidatos c
               LEFT JOIN candidato_detalles cd ON cd.email = c.email
               LEFT JOIN candidato_perfil cp ON cp.email = c.email
               LEFT JOIN niveles n ON n.id = cp.nivel_id
               LEFT JOIN modalidades m ON m.id = cp.modalidad_id
               LEFT JOIN disponibilidades d ON d.id = cp.disponibilidad_id
               WHERE c.email = ?
               LIMIT 1'
          );
          $stmt->execute([$email]);
          if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
              $profile['role'] = trim((string)($row['rol_deseado'] ?? ''));
              $profile['city'] = trim((string)($row['ciudad'] ?? ''));
              $profile['modalidad'] = $row['modalidad_nombre'] ?? null;
              $profile['disponibilidad'] = $row['disponibilidad_nombre'] ?? null;
              $profile['level'] = $row['nivel_nombre'] ?? null;
              $profile['summary'] = $row['resumen'] ?? null;
              $years = isset($row['anios_experiencia']) ? (int)$row['anios_experiencia'] : null;
              $profile['years'] = ($years && $years > 0) ? $years : null;
              $profile['skills'] = $row['habilidades']
                  ? array_values(array_filter(array_map('trim', explode(',', (string)$row['habilidades']))))
                  : [];
              $profile['skills_clean'] = self::cleanList($profile['skills']);
          }
        } catch (Throwable $e) {
            $profile['error'] = $e->getMessage();
        }

        if ($withEmbedding && self::getClient()) {
            try {
                $text = self::profileText($profile, $email);
                $vec = self::$client->embed($text);
                $profile['vector'] = $vec;
                $profile['norm'] = self::norm($vec);
            } catch (Throwable $e) {
                $profile['error'] = $profile['error'] ? $profile['error'].' | '.$e->getMessage() : $e->getMessage();
            }
        }

        return $profile;
    }

    public static function vacancyText(array $vacante): string
    {
        $parts = array_filter([
            'Título: '.self::normalize((string)($vacante['titulo'] ?? '')),
            'Descripción: '.self::normalize((string)($vacante['descripcion'] ?? '')),
            'Requisitos: '.self::normalize((string)($vacante['requisitos'] ?? '')),
            'Área: '.self::normalize((string)($vacante['area_nombre'] ?? '')),
            'Nivel: '.($vacante['nivel_nombre'] ?? ''),
            'Modalidad: '.($vacante['modalidad_nombre'] ?? ''),
            'Ciudad: '.self::normalize((string)($vacante['ciudad'] ?? '')),
            'Etiquetas: '.self::normalize((string)($vacante['etiquetas'] ?? '')),
        ]);
        return implode("\n", $parts);
    }

    public static function ensureVacancyEmbedding(PDO $pdo, array $vacante, ?OpenAIClient $client = null): ?array
    {
        $vacId = isset($vacante['id']) ? (int)$vacante['id'] : (int)($vacante['vacante_id'] ?? 0);
        if ($vacId <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT embedding, norm FROM vacante_embeddings WHERE vacante_id = ? LIMIT 1');
            $stmt->execute([$vacId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $embedding = json_decode((string)($row['embedding'] ?? ''), true);
                $norm = isset($row['norm']) ? (float)$row['norm'] : null;
                if (is_array($embedding)) {
                    return [
                        'embedding' => $embedding,
                        'norm' => $norm ?? self::norm($embedding),
                    ];
                }
            }
        } catch (Throwable $e) {
            // si falla, intentar regenerar
        }

        $client = $client ?: self::getClient();
        if (!$client) {
            return null;
        }

        try {
            $text = self::vacancyText($vacante);
            $vec = $client->embed($text);
            $norm = self::norm($vec);

            $insert = $pdo->prepare('
              INSERT INTO vacante_embeddings (vacante_id, embedding, norm, created_at, updated_at)
              VALUES (:vacante_id, :embedding, :norm, NOW(), NOW())
              ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), norm = VALUES(norm), updated_at = VALUES(updated_at)
            ');
            $insert->execute([
                ':vacante_id' => $vacId,
                ':embedding' => json_encode($vec),
                ':norm' => $norm,
            ]);

            return ['embedding' => $vec, 'norm' => $norm];
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function requiredYears(string $text): ?int
    {
        if (preg_match('/(\d+)\s*(?:anos|anios|años)/i', $text, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    public static function skillOverlap(array $candSkills, array $vacSkills): int
    {
        $cand = array_values(array_unique(array_filter($candSkills, static fn($v) => $v !== '')));
        $vac = array_values(array_unique(array_filter($vacSkills, static fn($v) => $v !== '')));
        if (!$cand || !$vac) {
            return 20;
        }
        $common = array_intersect($cand, $vac);
        $max = max(count($cand), count($vac));
        if ($max === 0) { return 20; }
        return (int)round(count($common) / $max * 100);
    }

    public static function expScore(?int $required, ?int $candYears): int
    {
        if ($required === null || $required <= 0 || $candYears === null) { return 20; }
        if ($candYears >= $required) { return 100; }
        return (int)round(max(0, ($candYears / $required) * 100));
    }

    /**
     * @return array{score:float,components:array<string,?float>,weights:array<string,float>}
     */
    public static function calculateScore(array $vacante, array $candidateProfile, ?array $vacEmbedding = null): array
    {
        $weights = ['embed' => 0.6, 'skills' => 0.3, 'exp' => 0.1];
        $scores = ['embed' => null, 'skills' => null, 'exp' => null];
        $activeWeights = [];

        // Embedding
        $candVec = $candidateProfile['vector'] ?? null;
        $candNorm = (float)($candidateProfile['norm'] ?? 0.0);
        if (is_array($candVec) && $candNorm > 0.0 && $vacEmbedding && is_array($vacEmbedding['embedding'] ?? null)) {
            $vacVec = $vacEmbedding['embedding'];
            $vacNorm = isset($vacEmbedding['norm']) ? (float)$vacEmbedding['norm'] : self::norm($vacVec);
            $cos = self::cosine($candVec, $candNorm, $vacVec, $vacNorm);
            $scores['embed'] = max(0, min(1, $cos)) * 100;
            $activeWeights['embed'] = $weights['embed'];
        }

        // Skills
        $vacTags = self::cleanList($vacante['etiquetas'] ?? '');
        $skillsClean = $candidateProfile['skills_clean'] ?? [];
        $scores['skills'] = self::skillOverlap($skillsClean, $vacTags);
        $activeWeights['skills'] = $weights['skills'];

        // Experiencia
        $reqYears = self::requiredYears(($vacante['descripcion'] ?? '').' '.($vacante['requisitos'] ?? ''));
        $candYears = $candidateProfile['years'] ?? null;
        $scores['exp'] = self::expScore($reqYears, $candYears);
        $activeWeights['exp'] = $weights['exp'];

        $totalWeight = array_sum($activeWeights);
        $weighted = 0.0;
        foreach ($activeWeights as $key => $w) {
            $weighted += ($scores[$key] ?? 0) * $w;
        }
        $score = $totalWeight > 0 ? $weighted / $totalWeight : 0.0;

        return [
            'score' => round(max(0, min(100, $score)), 2),
            'components' => $scores,
            'weights' => $activeWeights,
        ];
    }

    /**
     * Calcula el match completo entre una vacante y un candidato (por email) con datos estandarizados.
     * @return array{score:float,components:array<string,?float>,candidate:array<string,mixed>,vac_embedding:?array}
     */
    public static function scoreFor(PDO $pdo, array $vacante, string $candidateEmail): array
    {
        $candidate = self::candidateProfile($pdo, $candidateEmail, true);
        $vacEmbedding = self::ensureVacancyEmbedding($pdo, $vacante, self::getClient());
        $result = self::calculateScore($vacante, $candidate, $vacEmbedding);
        $result['candidate'] = $candidate;
        $result['vac_embedding'] = $vacEmbedding;
        return $result;
    }
}

