<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Services\OpenAIService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('openai:vacantes:embed {--vacante=} {--limit=}', function () {
    /** @var OpenAIService $openAI */
    $openAI = app(OpenAIService::class);

    if ((string) config('services.openai.key') === '') {
        $this->error('Configura OPENAI_API_KEY en el entorno.');
        return 1;
    }

    $vacanteId = $this->option('vacante');
    $limit = $this->option('limit') ? (int) $this->option('limit') : null;

    $query = DB::table('vacantes as v')
        ->leftJoin('areas as a', 'a.id', '=', 'v.area_id')
        ->leftJoin('niveles as n', 'n.id', '=', 'v.nivel_id')
        ->leftJoin('modalidades as m', 'm.id', '=', 'v.modalidad_id')
        ->where('v.estado', 'publicada');

    if ($vacanteId) {
        $query->where('v.id', $vacanteId);
    }

    if ($limit !== null && $limit > 0) {
        $query->limit($limit);
    }

    $vacantes = $query->get([
        'v.id',
        'v.titulo',
        'v.descripcion',
        'v.requisitos',
        'v.ciudad',
        'v.etiquetas',
        'a.nombre as area_nombre',
        'n.nombre as nivel_nombre',
        'm.nombre as modalidad_nombre',
    ]);

    if ($vacantes->isEmpty()) {
        $this->warn('No se encontraron vacantes para procesar.');
        return 0;
    }

    $this->info('Generando embeddings para ' . $vacantes->count() . ' vacantes...');

    foreach ($vacantes as $vacante) {
        $text = implode("\n", array_filter([
            'Título: ' . ($vacante->titulo ?? ''),
            'Descripción: ' . ($vacante->descripcion ?? ''),
            'Requisitos: ' . ($vacante->requisitos ?? ''),
            'Área: ' . ($vacante->area_nombre ?? ''),
            'Nivel: ' . ($vacante->nivel_nombre ?? ''),
            'Modalidad: ' . ($vacante->modalidad_nombre ?? ''),
            'Ciudad: ' . ($vacante->ciudad ?? ''),
            'Etiquetas: ' . ($vacante->etiquetas ?? ''),
        ]));

        $embedding = $openAI->embed($text);
        $norm = OpenAIService::norm($embedding);

        DB::table('vacante_embeddings')->updateOrInsert(
            ['vacante_id' => $vacante->id],
            [
                'embedding' => json_encode($embedding),
                'norm' => $norm,
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]
        );

        $this->line('- Vacante '.$vacante->id.' procesada');
    }

    $this->info('Embeddings de vacantes listos.');
})->purpose('Genera o actualiza embeddings de vacantes para recomendaciones');
