<?php

namespace App\Livewire;

use App\Models\MessageCategory;
use App\Services\ThreadService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ContactForm extends Component
{
    public $category = '';

    public $subject = '';

    public $message = '';

    public $submitted = false;

    public $threadId = null;

    protected $rules = [
        'category' => 'required|exists:message_categories,id',
        'subject' => 'required|string|max:200',
        'message' => 'required|string|max:2000',
    ];

    public function mount()
    {
        // Pre-select support category if available
        $supportCategory = MessageCategory::where('slug', 'support')->first();
        if ($supportCategory) {
            $this->category = $supportCategory->id;
        }
    }

    public function submit(ThreadService $threadService)
    {
        if (! Auth::check()) {
            session()->flash('error', __('contact.login_required'));

            return;
        }

        $this->validate();

        $category = MessageCategory::findOrFail($this->category);

        $thread = $threadService->createThread(
            user: Auth::user(),
            categorySlug: $category->slug,
            content: $this->message,
            thumb: null,
            source: 'contact-page',
            context: [
                'page_url' => url()->current(),
                'submitted_at' => now()->toIso8601String(),
            ],
            attachments: [],
            customTitle: $this->subject
        );

        $this->submitted = true;
        $this->threadId = $thread->id;

        session()->flash('status', __('contact.message_sent'));
    }

    public function getContactCategoriesProperty()
    {
        // Only show specific categories for contact form
        return MessageCategory::whereIn('slug', ['support', 'sales', 'pricing', 'bug'])
            ->where('is_visible', true)
            ->orderBy('order')
            ->get();
    }

    public function render()
    {
        return view('livewire.contact-form', [
            'categories' => $this->contactCategories,
        ]);
    }
}
