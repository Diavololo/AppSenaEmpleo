<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RecommendationController;

// Vista simple para probar recomendaciones desde el navegador
Route::view('/', 'recommend');

// API de recomendaciones (se usa también desde la vista anterior)
Route::get('/api/recomendaciones', [RecommendationController::class, 'index']);
