<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\CanteenNotification;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationLogController extends Controller
{
    public function index(Request $request): View
    {
        $typeFilter = strtoupper(trim((string) $request->query('type', '')));
        $pushStatusFilter = strtoupper(trim((string) $request->query('push_status', '')));

        $validTypes = array_map(fn (NotificationType $t) => $t->value, NotificationType::cases());
        if (! in_array($typeFilter, $validTypes, true)) {
            $typeFilter = '';
        }

        if (! in_array($pushStatusFilter, ['SKIPPED', 'SIMULATED', 'SENT', 'FAILED'], true)) {
            $pushStatusFilter = '';
        }

        $query = CanteenNotification::query()
            ->with('user:id,name,role')
            ->orderByDesc('id');

        if ($typeFilter !== '') {
            $query->where('type', $typeFilter);
        }

        if ($pushStatusFilter !== '') {
            $query->where('push_status', $pushStatusFilter);
        }

        return view('admin.canteen.notifications.index', [
            'notifications' => $query->paginate(20)->withQueryString(),
            'typeFilter' => $typeFilter,
            'pushStatusFilter' => $pushStatusFilter,
            'types' => $validTypes,
            'stats' => [
                'total' => CanteenNotification::query()->count(),
                'unread' => CanteenNotification::query()->whereNull('read_at')->count(),
                'push_failed' => CanteenNotification::query()->where('push_status', 'FAILED')->count(),
            ],
        ]);
    }
}
