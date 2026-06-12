<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * Dismiss an announcement for the current user/guest
     */
    public function dismiss(Request $request): JsonResponse
    {
        $request->validate([
            'announcement_id' => 'required|integer|exists:announcements,id',
        ]);

        $announcement = Announcement::find($request->announcement_id);

        if (! $announcement || ! $announcement->isCurrentlyVisible()) {
            return response()->json([
                'success' => false,
                'message' => 'Announcement not found or no longer active.',
            ], 404);
        }

        $user = auth()->user();

        if ($user) {
            // Logged-in user: store in database
            AnnouncementService::markAsSeenForUser($user, $announcement);

            return response()->json([
                'success' => true,
            ]);
        } else {
            // Guest: set cookie
            $cookie = AnnouncementService::markAsSeenForGuest($announcement);

            return response()->json([
                'success' => true,
            ])->withCookie($cookie);
        }
    }
}
