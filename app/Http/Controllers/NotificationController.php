<?php

namespace App\Http\Controllers;

use App\Support\Notifications\Destination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The notification inbox: every notification of the signed-in person (never anyone else's), a way to mark them all read,
 * and an "open" action that marks one read and follows the address it was stored with.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $user->notifications()->latest()->latest('id')->paginate(20)->withQueryString(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return redirect(Destination::safe($item->data['url'] ?? null) ?? route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->route('notifications.index')->with('status', 'All notifications marked as read.');
    }
}
