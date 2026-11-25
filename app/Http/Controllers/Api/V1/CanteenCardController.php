<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentCardResource;
use App\Services\StudentCardService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CanteenCardController extends Controller
{
    public function index(Request $request, StudentCardService $cardService): AnonymousResourceCollection
    {
        $cards = $cardService->listForUser($request->user());

        return StudentCardResource::collection($cards);
    }

    public function show(Request $request, int $cardId, StudentCardService $cardService): StudentCardResource
    {
        $card = $cardService->findForUserOrFail($request->user(), $cardId);

        return new StudentCardResource($card);
    }
}
