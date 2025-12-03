<?php

/**
 * DocumentAnalyzer
 *
 * Extrae texto de documentos (PDF/PNG/JPG) y calcula evidencias de CV/certificaciones.
 * Implementa cache por documento (hash) para evitar reprocesar y limitar el tiempo en los requests.
 */

class DocumentAnalyzer
{
    /** @var string */
    private $cacheDir;

    /** @var ?OpenAIClient */
    private $aiClient;

    /** @var string */
    private $openaiBase;

    /** @var int */
    private $pageLimit;

    /** @var float */
    private $timeout;

    public function __construct(?OpenAIClient $aiClient = null, string $cacheDir = null, int $pageLimit = 10, float $timeout = 6.0)
    {
        $this->aiClient = $aiClient;
        $this->openaiBase = rtrim(getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1', '/');
        $this->cacheDir = $cacheDir ?: dirname(__DIR__).'/storage/doc_cache';
        $this->pageLimit = $pageLimit;
        $this->timeout = $timeout;
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * Obtiene texto cacheado o lo extrae y cachea.
     *
     * @param array{id:int,ruta?:string,mime?:string,tipo?:string} $doc
     * @return array{text:string,source:string}|null
     */
    public function getDocumentText(array $doc): ?array
    {
        $path = $this->resolvePath($doc['ruta'] ?? '');
        if (!$path || !is_file($path)) {
            return null;
        }

        $hash = md5_file($path);
        $cacheFile = $this->cacheDir.'/doc_'.$doc['id'].'.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['hash'] ?? '') === $hash && isset($cached['text']) && trim((string)$cached['text']) !== '') {
                return ['text' => (string)$cached['text'], 'source' => (string)($cached['source'] ?? 'cache')];
            }
            // cache dañado o vacío, se recalcula
            @unlink($cacheFile);
        }

        $text = $this->extractText($path, $doc['mime'] ?? '', $doc['tipo'] ?? '');
        if ($text !== null && trim($text) !== '') {
            $text = $this->normalizeUtf8($text);
            $payload = [
                'hash' => $hash,
                'text' => $text,
                'source' => 'parsed',
                'cached_at' => date('c'),
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                file_put_contents($cacheFile, $json);
            }
            return ['text' => $text, 'source' => 'parsed'];
        }

        return null;
    }

    /**
     * Calcula evidencias y score de documentos de un candidato.
     *
     * @return array{score:float,flags:string[],cv_verified:bool,edu_total:int,edu_verified:int,exp_total:int,exp_verified:int}
     */
    public function candidateEvidence(PDO $pdo, string $email, array $profile): array
    {
        $email = trim(strtolower($email));
        $fullName = trim((string)($profile['fullName'] ?? ''));
        $nameTokens = array_filter(preg_split('/\s+/', strtolower($fullName)));
        $flags = [];

        // Datos base del candidato para validar contacto/nombre
        $candInfo = [
            'telefono' => null,
            'doc' => null,
            'ciudad' => null,
        ];
        try {
            $infoStmt = $pdo->prepare('SELECT telefono, ciudad FROM candidatos WHERE LOWER(email) = LOWER(?) LIMIT 1');
            $infoStmt->execute([$email]);
            if ($info = $infoStmt->fetch(PDO::FETCH_ASSOC)) {
                $candInfo['telefono'] = trim((string)($info['telefono'] ?? ''));
                $candInfo['ciudad'] = trim((string)($info['ciudad'] ?? ''));
            }
            $docStmt = $pdo->prepare('SELECT documento_numero FROM candidato_detalles WHERE LOWER(email) = LOWER(?) LIMIT 1');
            $docStmt->execute([$email]);
            if ($docRow = $docStmt->fetch(PDO::FETCH_ASSOC)) {
                $candInfo['doc'] = trim((string)($docRow['documento_numero'] ?? ''));
            }
        } catch (Throwable $e) {
            // silencioso
        }

        // CV principal
        $cvText = null;
        $cvVerified = false;
        try {
            $cvStmt = $pdo->prepare('SELECT id, ruta, mime, tipo FROM candidato_documentos WHERE LOWER(email) = ? AND tipo = "cv" ORDER BY uploaded_at DESC LIMIT 1');
            $cvStmt->execute([$email]);
            if ($cv = $cvStmt->fetch(PDO::FETCH_ASSOC)) {
                $parsed = $this->getDocumentText([
                    'id' => (int)$cv['id'],
                    'ruta' => $cv['ruta'] ?? '',
                    'mime' => $cv['mime'] ?? '',
                    'tipo' => $cv['tipo'] ?? '',
                ]);
                if ($parsed) {
                    $cvText = strtolower($parsed['text']);
                    $cvDigits = preg_replace('/\\D+/', '', $cvText ?? '');
                    $hasEmail = ($email !== '' && strpos($cvText, $email) !== false);
                    $hasName = $this->matchNameTokens($cvText, $nameTokens);
                    $hasPhone = false;
                    $phoneDigits = $this->onlyDigits($candInfo['telefono'] ?? '');
                    if ($phoneDigits !== '' && strlen($phoneDigits) >= 6) {
                        $hasPhone = strpos((string)$cvDigits, $phoneDigits) !== false;
                    }
                    $hasDocId = false;
                    $docDigits = $this->onlyDigits($candInfo['doc'] ?? '');
                    if ($docDigits !== '' && strlen($docDigits) >= 5) {
                        $hasDocId = strpos((string)$cvDigits, $docDigits) !== false;
                    }
                    $hasCity = false;
                    if (!empty($candInfo['ciudad'])) {
                        $hasCity = strpos($cvText, strtolower((string)$candInfo['ciudad'])) !== false;
                    }
                    // Más flexible: requiere nombre + (email o teléfono o doc o ciudad)
                    $cvVerified = $hasName && ($hasEmail || $hasPhone || $hasDocId || $hasCity);
                    if (!$hasName) { $flags[] = 'CV no coincide con nombre'; }
                    if (!$hasEmail && !$hasPhone && !$hasDocId && !$hasCity) { $flags[] = 'CV sin datos de contacto o ciudad'; }
                } else {
                    $flags[] = 'CV sin texto legible';
                }
            } else {
                $flags[] = 'Sin CV';
            }
        } catch (Throwable $e) {
            $flags[] = 'No se pudo leer el CV';
            error_log('[DocumentAnalyzer] cv: '.$e->getMessage());
        }

        // Educacion
        $eduTotal = 0; $eduVerified = 0;
        try {
            $eduStmt = $pdo->prepare('SELECT e.id, e.titulo, e.institucion, c.documento_id, d.ruta, d.mime FROM candidato_educacion e LEFT JOIN candidato_educacion_certificados c ON c.educacion_id = e.id LEFT JOIN candidato_documentos d ON d.id = c.documento_id WHERE LOWER(e.email) = LOWER(?)');
            $eduStmt->execute([$email]);
            foreach ($eduStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $eduTotal++;
                if (!empty($row['documento_id'])) {
                    $parsed = $this->getDocumentText([
                        'id' => (int)$row['documento_id'],
                        'ruta' => $row['ruta'] ?? '',
                        'mime' => $row['mime'] ?? '',
                        'tipo' => 'educacion',
                    ]);
                    $title = $row['titulo'] ?? '';
                    $inst  = $row['institucion'] ?? '';
                    if ($parsed && $this->eduMatches($parsed['text'], $title, $inst, $nameTokens)) { $eduVerified++; }
                }
            }
        } catch (Throwable $e) {
            $flags[] = 'Error al validar educacion';
            error_log('[DocumentAnalyzer] edu: '.$e->getMessage());
        }

        // Experiencia
        $expTotal = 0; $expVerified = 0;
        try {
            $expStmt = $pdo->prepare('SELECT e.id, e.cargo, e.empresa, c.documento_id, d.ruta, d.mime FROM candidato_experiencias e LEFT JOIN candidato_experiencia_certificados c ON c.experiencia_id = e.id LEFT JOIN candidato_documentos d ON d.id = c.documento_id WHERE LOWER(e.email) = LOWER(?)');
            $expStmt->execute([$email]);
            foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $expTotal++;
                if (!empty($row['documento_id'])) {
                    $parsed = $this->getDocumentText([
                        'id' => (int)$row['documento_id'],
                        'ruta' => $row['ruta'] ?? '',
                        'mime' => $row['mime'] ?? '',
                        'tipo' => 'experiencia',
                    ]);
                    $cargo = $row['cargo'] ?? '';
                    $empresa = $row['empresa'] ?? '';
                    if ($parsed && $this->expMatches($parsed['text'], $cargo, $empresa, $nameTokens)) { $expVerified++; }
                }
            }
        } catch (Throwable $e) {
            $flags[] = 'Error al validar experiencia';
            error_log('[DocumentAnalyzer] exp: '.$e->getMessage());
        }

        // Score
        $score = 1.0;
        // CV pesa pero no bloquea completamente
        if ($cvVerified) { $score += 0.1; } else { $score -= 0.08; }
        // Educación pesa más que experiencia
        $score += $this->ratioBonus($eduVerified, $eduTotal, 0.22);
        // Experiencia penaliza menos
        $score += $this->ratioBonus($expVerified, $expTotal, 0.06);
        if (($eduTotal + $expTotal) === 0) { $score -= 0.05; }
        // Bonificación por perfil robusto (mucha evidencia)
        $hasEvidence = ($eduVerified + $expVerified) >= 3 ? 0.07 : 0.0;
        $score += $hasEvidence;
        $score = max(0.6, min(1.35, $score));

        if (!$cvVerified) { $flags[] = 'CV no coincide con datos'; }
        if ($eduTotal > 0 && $eduVerified === 0) { $flags[] = 'Educacion sin soporte'; }
        if ($expTotal > 0 && $expVerified === 0) { $flags[] = 'Experiencia sin soporte'; }

        return [
            'score' => $score,
            'flags' => array_values(array_unique(array_filter($flags))),
            'cv_verified' => $cvVerified,
            'edu_total' => $eduTotal,
            'edu_verified' => $eduVerified,
            'exp_total' => $expTotal,
            'exp_verified' => $expVerified,
        ];
    }

    private function ratioBonus(int $hit, int $total, float $span): float
    {
        if ($total <= 0) { return 0.0; }
        $ratio = $hit / max(1, $total);
        // -span si 0, +span si 1
        return ($ratio - 0.5) * 2 * $span;
    }

    /**
     * @param string[] $needles
     * @param string[] $nameTokens
     */
    private function textMatches(string $text, array $needles, array $nameTokens): bool
    {
        $text = strtolower($text);
        foreach ($needles as $needle) {
            $needle = strtolower(trim((string)$needle));
            if ($needle !== '' && strpos($text, $needle) !== false) {
                return true;
            }
        }
        if ($nameTokens) {
            $hits = 0;
            foreach ($nameTokens as $token) {
                if (strlen($token) > 2 && strpos($text, $token) !== false) { $hits++; }
            }
            if ($hits >= max(1, count($nameTokens) - 1)) { return true; }
        }
        return false;
    }

    private function matchNameTokens(string $text, array $nameTokens): bool
    {
        $plain = $this->normalizeAscii($text);
        if (empty($nameTokens)) { return false; }
        $hits = 0;
        foreach ($nameTokens as $token) {
            $t = $this->normalizeAscii((string)$token);
            if (strlen($t) > 2 && strpos($plain, $t) !== false) { $hits++; }
        }
        return $hits >= max(1, count($nameTokens) - 1);
    }

    private function eduMatches(string $text, string $titulo, string $institucion, array $nameTokens): bool
    {
        $plain = $this->normalizeAscii($text);
        $tit = $this->normalizeAscii($titulo);
        $inst = $this->normalizeAscii($institucion);
        $hits = 0;
        if ($tit !== '' && strpos($plain, $tit) !== false) { $hits++; }
        if ($inst !== '' && strpos($plain, $inst) !== false) { $hits++; }
        if ($hits > 0) { return true; }
        // fallback: dos tokens del título/inst alcanzan
        $tokens = array_filter(preg_split('/[\\s,]+/', trim($tit.' '.$inst)));
        $hitTokens = 0;
        foreach ($tokens as $tk) {
            if (strlen($tk) > 3 && strpos($plain, $tk) !== false) { $hitTokens++; }
        }
        if ($hitTokens >= 2) { return true; }
        return $this->matchNameTokens($text, $nameTokens);
    }

    private function expMatches(string $text, string $cargo, string $empresa, array $nameTokens): bool
    {
        $plain = $this->normalizeAscii($text);
        $cg = $this->normalizeAscii($cargo);
        $em = $this->normalizeAscii($empresa);
        if ($cg !== '' && strpos($plain, $cg) !== false) { return true; }
        if ($em !== '' && strpos($plain, $em) !== false) { return true; }
        $tokens = array_filter(preg_split('/[\\s,]+/', trim($cg.' '.$em)));
        $hitTokens = 0;
        foreach ($tokens as $tk) {
            if (strlen($tk) > 3 && strpos($plain, $tk) !== false) { $hitTokens++; }
        }
        if ($hitTokens >= 2) { return true; }
        return $this->matchNameTokens($text, $nameTokens);
    }

    private function resolvePath(string $ruta): ?string
    {
        $ruta = trim($ruta);
        if ($ruta === '') { return null; }

        $base = dirname(__DIR__);
        $norm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta);

        // Ruta absoluta Windows (con letra de unidad)
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $ruta) === 1) {
            return is_file($ruta) ? realpath($ruta) : null;
        }

        // Ruta absoluta tipo *nix (ej. WSL) -> se usa tal cual
        if (str_starts_with($ruta, DIRECTORY_SEPARATOR) && !str_starts_with($ruta, '/uploads')) {
            return is_file($ruta) ? realpath($ruta) : null;
        }

        // Rutas almacenadas como "/uploads/..." deben resolverse relativo al proyecto
        if (str_starts_with($ruta, '/uploads')) {
            $candidate = $base.$ruta;
            if (is_file($candidate)) { return realpath($candidate); }
        }

        // Rutas relativas "uploads/..." o similares
        $candidate = $base.DIRECTORY_SEPARATOR.$norm;
        if (is_file($candidate)) { return realpath($candidate); }

        // Último intento: tal cual normalizado
        return is_file($norm) ? realpath($norm) : null;
    }

    private function normalizeUtf8(string $text): string
    {
        // Limpia caracteres de control y normaliza a UTF-8 para evitar fallos en json_encode
        $text = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]+/', ' ', $text);
        $enc = function_exists('mb_detect_encoding') ? mb_detect_encoding($text, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) : false;
        if ($enc && $enc !== 'UTF-8') {
            $text = mb_convert_encoding($text, 'UTF-8', $enc);
        } else {
            $conv = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($conv !== false) { $text = $conv; }
        }
        return trim((string)$text);
    }

    private function normalizeAscii(string $text): string
    {
        $text = $this->normalizeUtf8($text);
        $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($plain === false) { $plain = $text; }
        return strtolower($plain);
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\\D+/', '', $value);
    }

    private function extractText(string $path, string $mime, string $type): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($mime === '') {
            $mime = $this->guessMime($ext);
        }
        if (strpos($mime, 'pdf') !== false || $ext === 'pdf') {
            $pdf = $this->pdfToText($path);
            if ($pdf !== null && trim($pdf) !== '') { return trim($pdf); }
            // Fallback OCR directo al PDF si no hay texto embebido
            $pdfOcr = $this->ocrPdf($path);
            if ($pdfOcr !== null && trim($pdfOcr) !== '') { return trim($pdfOcr); }
        }

        if (preg_match('/(png|jpe?g|bmp|tif|tiff)$/', $ext)) {
            $ocr = $this->ocrImage($path);
            if ($ocr !== null && trim($ocr) !== '') { return trim($ocr); }
        }

        if ($ext === 'docx') {
            $docx = $this->docxToText($path);
            if ($docx !== null && trim($docx) !== '') { return trim($docx); }
        }

        return null;
    }

    private function docxToText(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) { return null; }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) { return null; }
        $index = $zip->locateName('word/document.xml');
        if ($index === false) { $zip->close(); return null; }
        $xml = $zip->getFromIndex($index);
        $zip->close();
        if (!is_string($xml) || trim($xml) === '') { return null; }
        // elimina tags y decodifica entidades
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return $text !== '' ? $text : null;
    }

    private function ocrPdf(string $path): ?string
    {
        $tessBin = $this->findBinary('tesseract', [
            'C:\Program Files\Tesseract-OCR\tesseract.exe',
            'C:\Program Files (x86)\Tesseract-OCR\tesseract.exe',
        ]);
        if (!$tessBin) { return null; }
        $cmd = escapeshellarg($tessBin).' '.escapeshellarg($path).' stdout -l spa+eng --psm 6';
        $text = $this->runCommand($cmd, $this->timeout);
        return $text !== null && trim($text) !== '' ? $text : null;
    }

    private function pdfToText(string $path): ?string
    {
        $bin = $this->findBinary('pdftotext', [
            'C:\Program Files\Git\mingw64\bin\pdftotext.exe',
            'C:\Program Files\\poppler\\bin\\pdftotext.exe',
            'C:\Program Files (x86)\\poppler\\bin\\pdftotext.exe',
        ]);
        if (!$bin) { return null; }
        $cmd = escapeshellarg($bin).' -l '.$this->pageLimit.' -q '.escapeshellarg($path).' -';
        $text = $this->runCommand($cmd, $this->timeout);
        return $text !== null && trim($text) !== '' ? $text : null;
    }

    private function ocrImage(string $path): ?string
    {
        // 1) Tesseract local
        $tessBin = $this->findBinary('tesseract', [
            'C:\Program Files\Tesseract-OCR\tesseract.exe',
            'C:\Program Files (x86)\Tesseract-OCR\tesseract.exe',
        ]);
        if ($tessBin) {
            $tmp = tempnam(sys_get_temp_dir(), 'ocr');
            if ($tmp !== false) {
                $cmd = escapeshellarg($tessBin).' '.escapeshellarg($path).' '.escapeshellarg($tmp).' -l spa+eng --psm 6';
                $ok = $this->runCommand($cmd, $this->timeout);
                $txtFile = $tmp.'.txt';
                $text = is_file($txtFile) ? (string)file_get_contents($txtFile) : null;
                @unlink($txtFile);
                @unlink($tmp);
                if ($text !== null && trim($text) !== '') { return $text; }
            }
        }
        // 2) OpenAI Vision (si cliente disponible)
        if ($this->aiClient) {
            try {
                $b64 = base64_encode((string)file_get_contents($path));
                $mime = $this->guessMime(strtolower(pathinfo($path, PATHINFO_EXTENSION)));
                return $this->visionOcr($b64, $mime);
            } catch (Throwable $e) {
                error_log('[DocumentAnalyzer] vision ocr: '.$e->getMessage());
            }
        }
        return null;
    }

    private function visionOcr(string $b64, string $mime): ?string
    {
        $apiKey = getenv('OPENAI_API_KEY');
        if (!$apiKey) { return null; }
        // Usa chat con imagen (gpt-4o-mini soporta vision)
        $payload = json_encode([
            'model' => getenv('OPENAI_VISION_MODEL') ?: 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Extrae el texto legible tal cual aparece. Devuelve solo texto, sin comentarios adicionales.'],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:'.$mime.';base64,'.$b64,
                            ],
                        ],
                    ],
                ],
            ],
            'max_tokens' => 600,
            'temperature' => 0.0,
        ]);

        $ch = curl_init($this->openaiBase.'/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . 'Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: '.$err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($status >= 400) {
            $msg = $data['error']['message'] ?? 'API error '.$status;
            throw new RuntimeException($msg);
        }
        $content = $data['choices'][0]['message']['content'] ?? null;
        return is_string($content) ? trim($content) : null;
    }

    private function runCommand(string $cmd, float $timeout): ?string
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            return null;
        }
        stream_set_timeout($pipes[1], (int)ceil($timeout));
        $output = stream_get_contents($pipes[1]);
        foreach ($pipes as $p) { @fclose($p); }
        $status = proc_close($process);
        if ($status !== 0 && $output === false) {
            return null;
        }
        return $output !== false ? $output : null;
    }

    private function commandExists(string $command): bool
    {
        $where = stripos(PHP_OS_FAMILY, 'Windows') === false ? 'command -v' : 'where';
        $process = proc_open($where.' '.$command, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) { return false; }
        $output = stream_get_contents($pipes[1]);
        foreach ($pipes as $p) { @fclose($p); }
        $status = proc_close($process);
        return ($status === 0) && is_string($output) && trim($output) !== '';
    }

    private function findBinary(string $command, array $fallbackPaths = []): ?string
    {
        if ($this->commandExists($command)) { return $command; }
        foreach ($fallbackPaths as $path) {
            if ($path && is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    private function guessMime(string $ext): string
    {
        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'bmp' => 'image/bmp',
            'tif', 'tiff' => 'image/tiff',
            default => 'application/octet-stream',
        };
    }
}
