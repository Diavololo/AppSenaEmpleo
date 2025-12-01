<?php

namespace App\Http\Controllers;

use App\Services\RecommenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecommendationController extends Controller
{
    public function __construct(private readonly RecommenderService $recommender)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $email = (string) $request->query('email', '');
        if ($email === '') {
            return response()->json(['error' => 'El parámetro email es obligatorio'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limit = (int) $request->query('limit', 10);
        $limit = $limit > 0 ? min($limit, 50) : 10;
        $withExplanation = filter_var($request->query('explain', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $data = $this->recommender->recommendForEmail($email, $limit, $withExplanation);
            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
