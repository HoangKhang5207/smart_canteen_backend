<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;

class StatisticsController extends Controller
{
    public function __construct(
        private readonly StatisticsService $statisticsService,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->statisticsService->getTodaySummary(),
        ]);
    }
}
