<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RecommenderService
{
    public function __construct(private readonly OpenAIService $openAI)
    {
    }

    /**
     * @return array{email:string,total_considered:int,recommendations:array<int,array<string,mixed>>}
     */
    public function recommendForEmail(string $email, int $limit = 10, bool $withExplanation = false): array
    {
        $profile = $this->buildUserProfileText($email);
        if ($profile === null) {
            throw new RuntimeException('No se encontró un perfil para este email.');
        }

        $userEmbedding = $this->openAI->embed($profile['text']);
        $userNorm = OpenAIService::norm($userEmbedding);

        $vacantes = $this->loadVacantes($profile['filters']);
        $recommendations = [];

        foreach ($vacantes as $vacante) {
            $embedding = json_decode((string) $vacante->embedding, true);
            if (!is_array($embedding)) {
                continue;
            }

            $vacNorm = (float) ($vacante->norm ?? 0.0);
            $score = OpenAIService::cosine($userEmbedding, $userNorm, $embedding, $vacNorm);

            $recommendations[] = [
                'id' => (int) $vacante->id,
                'titulo' => $vacante->titulo,
                'ciudad' => $vacante->ciudad,
                'area' => $vacante->area_nombre,
                'nivel' => $vacante->nivel_nombre,
                'modalidad' => $vacante->modalidad_nombre,
                'salario' => [
                    'min' => $vacante->salario_min,
                    'max' => $vacante->salario_max,
                    'moneda' => $vacante->moneda,
                ],
                'score' => round($score, 4),
                'resumen' => $this->shorten((string) $vacante->descripcion, 360),
                'requisitos' => $this->shorten((string) ($vacante->requisitos ?? ''), 240),
                'etiquetas' => $vacante->etiquetas ? array_values(array_filter(array_map('trim', explode(',', $vacante->etiquetas)))) : [],
            ];
        }

        usort($recommendations, static fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $recommendations = array_slice($recommendations, 0, max(1, $limit));

        if ($withExplanation && !empty($recommendations)) {
            $maxExplain = min(count($recommendations), 3);
            $profileSummary = $profile['summary_for_llm'];
            for ($i = 0; $i < $maxExplain; $i++) {
                $prompt = $this->buildExplanationPrompt($profileSummary, $recommendations[$i]);
                $recommendations[$i]['explicacion'] = $this->openAI->chat($prompt);
            }
        }

        return [
            'email' => $email,
            'total_considered' => count($vacantes),
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @return array{filters:array<string, int|string|null>,text:string,summary_for_llm:string}|null
     */
    private function buildUserProfileText(string $email): ?array
    {
        $profile = DB::table('candidatos as c')
            ->leftJoin('candidato_detalles as cd', 'cd.email', '=', 'c.email')
            ->leftJoin('candidato_perfil as cp', 'cp.email', '=', 'c.email')
            ->leftJoin('areas as a', 'a.id', '=', 'cp.area_id')
            ->leftJoin('niveles as n', 'n.id', '=', 'cp.nivel_id')
            ->leftJoin('modalidades as m', 'm.id', '=', 'cp.modalidad_id')
            ->leftJoin('disponibilidades as d', 'd.id', '=', 'cp.disponibilidad_id')
            ->select([
                'c.email',
                'c.nombres',
                'c.apellidos',
                'c.ciudad',
                'cd.perfil as resumen',
                'cd.areas_interes',
                'cp.rol_deseado',
                'cp.area_id',
                'cp.nivel_id',
                'cp.modalidad_id',
                'cp.habilidades',
                'cp.anios_experiencia',
                'a.nombre as area_nombre',
                'n.nombre as nivel_nombre',
                'm.nombre as modalidad_nombre',
                'd.nombre as disponibilidad_nombre',
            ])
            ->where('c.email', $email)
            ->first();

        if ($profile === null) {
            return null;
        }

        $skills = DB::table('candidato_habilidades')
            ->where('email', $email)
            ->orderByDesc('anios_experiencia')
            ->orderBy('nombre')
            ->get()
            ->map(function ($row) {
                $years = $row->anios_experiencia !== null ? ' (' . $row->anios_experiencia . ' años)' : '';
                return trim((string) $row->nombre . $years);
            })
            ->filter()
            ->values()
            ->all();

        $experiencias = DB::table('candidato_experiencias')
            ->where('email', $email)
            ->orderBy('orden')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $pieces = [
                    $row->cargo,
                    $row->empresa ? 'en ' . $row->empresa : null,
                    $row->periodo,
                ];
                $title = trim(implode(' ', array_filter($pieces)));
                $desc = $this->shorten((string) ($row->descripcion ?? ''), 180);
                return trim($title . '. ' . $desc);
            })
            ->filter()
            ->values()
            ->all();

        $educacion = DB::table('candidato_educacion')
            ->where('email', $email)
            ->orderBy('orden')
            ->limit(3)
            ->get()
            ->map(function ($row) {
                $pieces = [
                    $row->titulo,
                    $row->institucion ? 'en ' . $row->institucion : null,
                    $row->periodo,
                ];
                return trim(implode(' ', array_filter($pieces)));
            })
            ->filter()
            ->values()
            ->all();

        $parts = array_filter([
            'Rol deseado: ' . ($profile->rol_deseado ?? ''),
            'Área: ' . ($profile->area_nombre ?? ''),
            'Nivel: ' . ($profile->nivel_nombre ?? ''),
            'Modalidad preferida: ' . ($profile->modalidad_nombre ?? ''),
            'Disponibilidad: ' . ($profile->disponibilidad_nombre ?? ''),
            'Ciudad: ' . ($profile->ciudad ?? ''),
            'Resumen: ' . ($profile->resumen ?? ''),
            'Áreas de interés: ' . ($profile->areas_interes ?? ''),
            'Habilidades declaradas: ' . ($profile->habilidades ?? ''),
            $skills ? 'Habilidades detalladas: ' . implode(', ', $skills) : null,
            $experiencias ? 'Experiencia: ' . implode(' | ', $experiencias) : null,
            $educacion ? 'Educación: ' . implode(' | ', $educacion) : null,
        ]);

        $text = implode("\n", $parts);

        $summaryForLLM = sprintf(
            'Rol: %s. Área: %s. Nivel: %s. Modalidad: %s. Ciudad: %s. Habilidades: %s. Experiencia: %s.',
            $profile->rol_deseado ?? 'sin rol',
            $profile->area_nombre ?? 'sin área',
            $profile->nivel_nombre ?? 'sin nivel',
            $profile->modalidad_nombre ?? 'sin modalidad',
            $profile->ciudad ?? 'sin ciudad',
            $skills ? implode(', ', $skills) : ($profile->habilidades ?? 'sin habilidades'),
            $experiencias ? implode(' | ', $experiencias) : 'sin experiencia'
        );

        $filters = [
            'ciudad' => $profile->ciudad ?? null,
            'area_id' => $profile->area_id ?? null,
            'nivel_id' => $profile->nivel_id ?? null,
            'modalidad_id' => $profile->modalidad_id ?? null,
        ];

        return [
            'filters' => $filters,
            'text' => $text,
            'summary_for_llm' => $summaryForLLM,
        ];
    }

    private function loadVacantes(array $filters)
    {
        $query = DB::table('vacantes as v')
            ->join('vacante_embeddings as ve', 've.vacante_id', '=', 'v.id')
            ->leftJoin('areas as a', 'a.id', '=', 'v.area_id')
            ->leftJoin('niveles as n', 'n.id', '=', 'v.nivel_id')
            ->leftJoin('modalidades as m', 'm.id', '=', 'v.modalidad_id')
            ->where('v.estado', 'publicada');

        if (!empty($filters['ciudad'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('v.ciudad', $filters['ciudad'])
                    ->orWhere('v.ciudad', 'Remoto');
            });
        }

        if (!empty($filters['area_id'])) {
            $query->where('v.area_id', $filters['area_id']);
        }

        if (!empty($filters['nivel_id'])) {
            $query->where('v.nivel_id', $filters['nivel_id']);
        }

        if (!empty($filters['modalidad_id'])) {
            $query->where('v.modalidad_id', $filters['modalidad_id']);
        }

        return $query->get([
            'v.id',
            'v.titulo',
            'v.descripcion',
            'v.requisitos',
            'v.ciudad',
            'v.area_id',
            'v.nivel_id',
            'v.modalidad_id',
            'v.salario_min',
            'v.salario_max',
            'v.moneda',
            'v.etiquetas',
            'a.nombre as area_nombre',
            'n.nombre as nivel_nombre',
            'm.nombre as modalidad_nombre',
            've.embedding',
            've.norm',
        ]);
    }

    private function shorten(string $text, int $maxLength): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (Str::length($normalized) <= $maxLength) {
            return $normalized;
        }

        return Str::limit($normalized, $maxLength, '...');
    }

    private function buildExplanationPrompt(string $profileSummary, array $vacante): string
    {
        $vacanteSummary = sprintf(
            'Vacante: %s. Ciudad: %s. Área: %s. Nivel: %s. Modalidad: %s. Requisitos: %s. Etiquetas: %s.',
            $vacante['titulo'],
            $vacante['ciudad'] ?? 'N/D',
            $vacante['area'] ?? 'N/D',
            $vacante['nivel'] ?? 'N/D',
            $vacante['modalidad'] ?? 'N/D',
            $vacante['requisitos'] ?? '',
            $vacante['etiquetas'] ? implode(', ', $vacante['etiquetas']) : ''
        );

        return "Da una explicación breve (máx 1 frase) de por qué el perfil encaja con la vacante.\nPerfil: {$profileSummary}\n{$vacanteSummary}";
    }
}
