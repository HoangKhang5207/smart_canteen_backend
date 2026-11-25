<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CanteenNotificationResource;
use App\Models\CanteenNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'unread_only' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', Rule::in(array_map(fn (NotificationType $t) => $t->value, NotificationType::cases()))],
        ]);

        $userId = (int) $request->user()->id;
        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 15;
        $unreadOnly = $request->boolean('unread_only');
        $typeFilter = isset($validated['type']) ? (string) $validated['type'] : null;

        $query = CanteenNotification::query()
            ->where('user_id', $userId)
            ->orderByDesc('id');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        if ($typeFilter !== null && $typeFilter !== '') {
            $query->where('type', $typeFilter);
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $unreadCount = CanteenNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => CanteenNotificationResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = CanteenNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    public function markAsRead(Request $request, int $id): JsonResponse
    {
        /** @var CanteenNotification|null $notification */
        $notification = CanteenNotification::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($notification === null) {
            abort(404);
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'data' => (new CanteenNotificationResource($notification->fresh()))->resolve(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $marked = CanteenNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => [
                'marked_count' => $marked,
                'unread_count' => 0,
            ],
        ]);
    }
}
