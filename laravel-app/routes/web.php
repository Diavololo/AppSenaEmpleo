<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RecommendationController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/recomendaciones', [RecommendationController::class, 'index']);
