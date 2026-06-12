<?php

namespace App\Http\Controllers\Profile;

use App\Exceptions\VirusDetectedException;
use App\Http\Controllers\Controller;
use App\Models\MessageCategory;
use App\Models\MessageThread;
use App\Services\MessageService;
use App\Services\ThreadService;
use App\Services\Virusscan\VirusScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function __construct(
        private ThreadService $threadService,
        private VirusScanService $virusScanService
    ) {}

    /**
     * Process and scan uploaded attachments
     *
     * @param  array  $uploadedFiles  Array of UploadedFile instances
     * @param  int  $userId  User ID for virus scan tier checking
     * @param  MessageService  $messageService  Service for storing attachments
     * @return array|null  Array of stored attachment paths, or null if virus detected
     */
    private function processAttachments(array $uploadedFiles, int $userId, MessageService $messageService): ?array
    {
        // Scan files for viruses before storing
        try {
            $filePaths = array_map(fn ($file) => $file->getRealPath(), $uploadedFiles);
            $this->virusScanService->scanFilesOrFail($filePaths, $userId);
        } catch (VirusDetectedException $e) {
            return null;
        }

        $attachments = [];
        foreach ($uploadedFiles as $file) {
            $attachments[] = $messageService->storeAttachment($file);
        }

        return $attachments;
    }

    /**
     * Display list of user's message threads.
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $threads = $user->messageThreads()
            ->with(['category', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->paginate(20);

        $unreadCount = $user->messageThreads()
            ->where('unread_count_user', '>', 0)
            ->count();

        // Get categories for the new conversation form
        $categories = MessageCategory::whereIn('slug', ['support', 'sales', 'pricing', 'bug'])
            ->where('is_visible', true)
            ->orderBy('order')
            ->get();

        return view('profile.messages', [
            'threads' => $threads,
            'unreadCount' => $unreadCount,
            'categories' => $categories,
        ]);
    }

    /**
     * Store a new conversation thread.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, MessageService $messageService): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'category_id' => 'required|exists:message_categories,id',
            'content' => 'required|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,pdf', // 20MB
        ]);

        $category = MessageCategory::findOrFail($validated['category_id']);

        // Handle attachments with virus scanning
        $attachments = [];
        if ($request->hasFile('attachments')) {
            $attachments = $this->processAttachments(
                $request->file('attachments'),
                $user->id,
                $messageService
            );

            if ($attachments === null) {
                return back()
                    ->withInput()
                    ->with('error', __('errors.virus_detected_message'));
            }
        }

        // Generate title from first line of content
        $title = \Str::limit($validated['content'], 50);

        $thread = $this->threadService->createThread(
            user: $user,
            categorySlug: $category->slug,
            content: $validated['content'],
            thumb: null,
            source: 'profile-messages',
            context: [
                'page_url' => url()->previous(),
                'submitted_at' => now()->toIso8601String(),
            ],
            attachments: $attachments,
            customTitle: $title
        );

        return redirect()
            ->route('profile.messages.show', $thread)
            ->with('status', __('messages.question_sent'));
    }

    /**
     * Display a single thread with all messages.
     *
     * @return \Illuminate\View\View
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function show(Request $request, MessageThread $thread): View
    {
        $user = $request->user();

        // Ensure user owns this thread
        if ($thread->user_id !== $user->id) {
            abort(403);
        }

        // Load messages
        $thread->load(['category', 'messages.sender']);

        // Mark as read for user
        $thread->markReadForUser();

        return view('profile.message-thread', [
            'thread' => $thread,
        ]);
    }

    /**
     * Send a reply to a thread.
     *
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function reply(Request $request, MessageThread $thread, MessageService $messageService): RedirectResponse
    {
        $user = $request->user();

        // Ensure user owns this thread
        if ($thread->user_id !== $user->id) {
            abort(403);
        }

        // Validate
        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,pdf', // 20MB
        ]);

        // Check if thread is closed
        if ($thread->isClosed()) {
            return back()->with('error', __('messages.thread_closed'));
        }

        // Handle attachments with virus scanning
        $attachments = [];
        if ($request->hasFile('attachments')) {
            $attachments = $this->processAttachments(
                $request->file('attachments'),
                $user->id,
                $messageService
            );

            if ($attachments === null) {
                return back()
                    ->withInput()
                    ->with('error', __('errors.virus_detected_message'));
            }
        }

        // Add user reply
        $this->threadService->addMessage(
            $thread,
            $user,
            $validated['content'],
            $attachments,
            MessageThread::SENDER_USER
        );

        // Update thread status back to open if it was waiting
        if ($thread->status === MessageThread::STATUS_WAITING_FOR_USER) {
            $thread->update(['status' => MessageThread::STATUS_OPEN]);
        }

        return back()->with('status', __('messages.reply_sent'));
    }

    /**
     * Check for new messages (polling endpoint).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkNew(Request $request, MessageThread $thread): JsonResponse
    {
        $user = $request->user();

        // Ensure user owns this thread
        if ($thread->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $lastMessageId = (int) $request->input('last_message_id', 0);

        $newMessages = $thread->messages()
            ->where('id', '>', $lastMessageId)
            ->with('sender')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'content' => $msg->content,
                'content_html' => $msg->sender_type === 'admin'
                    ? \Illuminate\Support\Str::markdown($msg->content)
                    : linkify(e($msg->content)),
                'sender_type' => $msg->sender_type,
                'sender_name' => $msg->sender?->name ?? ($msg->sender_type === 'admin' ? config('app.name') . ' Support' : 'System'),
                'created_at' => $msg->created_at->toISOString(),
                'is_mine' => $msg->sender_type === MessageThread::SENDER_USER,
            ]);

        // Mark as read if there are new messages
        if ($newMessages->isNotEmpty()) {
            $thread->markReadForUser();
        }

        return response()->json([
            'messages' => $newMessages,
            'thread_status' => $thread->fresh()->status,
            'unread_count' => 0,
        ]);
    }

    /**
     * Get unread count for nav badge.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()
            ->messageThreads()
            ->where('unread_count_user', '>', 0)
            ->count();

        return response()->json(['count' => $count]);
    }
}
