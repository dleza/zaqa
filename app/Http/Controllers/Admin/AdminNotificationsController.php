<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class AdminNotificationsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 403);

        $filter = strtolower(trim((string) $request->query('filter', 'all')));
        if (! in_array($filter, ['all', 'unread', 'read'], true)) {
            $filter = 'all';
        }

        $query = match ($filter) {
            'unread' => $user->unreadNotifications(),
            'read' => $user->readNotifications(),
            default => $user->notifications(),
        };

        $notifications = $query
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => [
                'id' => (string) $n->id,
                'type' => (string) $n->type,
                'title' => (string) ($n->data['title'] ?? ''),
                'message' => (string) ($n->data['message'] ?? ''),
                'link_url' => (string) ($n->data['link_url'] ?? ''),
                'data' => $n->data,
                'read_at' => optional($n->read_at)?->toIso8601String(),
                'created_at' => optional($n->created_at)?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Notifications/Index', [
            'notifications' => $notifications,
            'filter' => $filter,
            'unreadCount' => fn () => $user->unreadNotifications()->count(),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        /** @var DatabaseNotification $row */
        $row = $user->notifications()->whereKey($notification)->firstOrFail();
        if (! $row->read_at) {
            $row->markAsRead();
        }

        $url = (string) ($row->data['link_url'] ?? '');
        if ($url !== '' && str_starts_with($url, '/')) {
            return redirect($url);
        }

        return back();
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        /** @var DatabaseNotification $row */
        $row = $user->notifications()->whereKey($notification)->firstOrFail();
        if (! $row->read_at) {
            $row->markAsRead();
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $user->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}

