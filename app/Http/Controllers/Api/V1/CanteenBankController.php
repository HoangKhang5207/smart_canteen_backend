<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\BankingGatewayInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CanteenBankController extends Controller
{
    public function index(BankingGatewayInterface $gateway): JsonResponse
    {
        return response()->json([
            'data' => $gateway->listSupportedBanks(),
        ]);
    }
}
