@props([
    'converterType' => null,
    'pageUrl' => null,
])

@auth
<div
    x-data="feedbackWidget()"
    x-cloak
    class="flex flex-wrap items-start gap-6"
>
    {{-- Thumb buttons --}}
    <div class="flex flex-col items-center">
        <p class="text-gray-600 mb-3 text-sm">{{ __('feedback.question') }}</p>
        <div class="flex gap-2">
            {{-- Thumbs up --}}
            <button
                type="button"
                @click="selectThumb('up')"
                :class="thumb === 'up' ? 'border-green-500 bg-green-50' : 'border-gray-200 hover:border-green-300'"
                class="w-16 h-16 flex items-center justify-center border-2 rounded-lg transition-all duration-200 text-3xl"
            >
                👍
            </button>
            {{-- Thumbs down --}}
            <button
                type="button"
                @click="selectThumb('down')"
                :class="thumb === 'down' ? 'border-red-500 bg-red-50' : 'border-gray-200 hover:border-red-300'"
                class="w-16 h-16 flex items-center justify-center border-2 rounded-lg transition-all duration-200 text-3xl"
            >
                👎
            </button>
        </div>
    </div>

    {{-- Feedback form (shows after thumb selection) --}}
    <div
        x-show="thumb !== null && !submitted"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 transform translate-x-4"
        x-transition:enter-end="opacity-100 transform translate-x-0"
        class="flex-1 min-w-[280px]"
    >
        <p class="text-gray-700 font-medium mb-2">
            <span x-show="thumb === 'up'">{{ __('feedback.prompt_positive') }} 👍</span>
            <span x-show="thumb === 'down'">{{ __('feedback.prompt_negative') }} 👎</span>
        </p>

        <form @submit.prevent="submitFeedback">
            <textarea
                x-model="content"
                x-ref="textarea"
                maxlength="500"
                rows="4"
                class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 resize-y"
                :placeholder="thumb === 'up' ? '{{ __('feedback.placeholder_positive') }}' : '{{ __('feedback.placeholder_negative') }}'"
            ></textarea>

            <div class="flex items-center justify-between mt-2">
                <button
                    type="submit"
                    :disabled="submitting"
                    class="px-6 py-2 bg-white border-2 border-primary-500 text-primary-600 rounded-full hover:bg-primary-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed font-medium"
                >
                    <span x-show="!submitting">{{ __('feedback.submit') }}</span>
                    <span x-show="submitting">{{ __('feedback.submitting') }}</span>
                </button>

                <span class="text-sm text-gray-500">
                    <span x-text="content.length"></span>/500 {{ __('feedback.characters') }}
                </span>
            </div>
        </form>
    </div>

    {{-- Success message --}}
    <div
        x-show="submitted"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        class="flex items-center gap-2 text-green-600"
    >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <span>{{ __('feedback.thank_you') }}</span>
    </div>

    {{-- Error message --}}
    <div
        x-show="error"
        x-transition
        class="text-red-600 text-sm"
        x-text="error"
    ></div>
</div>

<script>
function feedbackWidget() {
    return {
        thumb: null,
        content: '',
        submitting: false,
        submitted: false,
        error: null,

        selectThumb(value) {
            this.thumb = value;
            this.submitted = false;
            this.error = null;
            this.$nextTick(() => {
                if (this.$refs.textarea) {
                    this.$refs.textarea.focus();
                }
            });
        },

        async submitFeedback() {
            this.submitting = true;
            this.error = null;

            try {
                const response = await fetch('{{ route("feedback.store") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        thumb: this.thumb,
                        content: this.content,
                        converter_type: '{{ $converterType }}',
                        page_url: '{{ $pageUrl ?? request()->url() }}',
                    }),
                });

                const data = await response.json();

                if (response.ok) {
                    this.submitted = true;
                    this.content = '';
                } else {
                    this.error = data.message || '{{ __("feedback.error") }}';
                }
            } catch (e) {
                this.error = '{{ __("feedback.error") }}';
            } finally {
                this.submitting = false;
            }
        }
    };
}
</script>
@endauth
