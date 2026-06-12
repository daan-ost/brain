@extends('layouts.app')

@section('content')
@php
// Blog posts are not available without a CMS — always show 404
$post = null;
@endphp

@if($post)
<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-gradient-to-br from-[#9FD6D2] to-[#53B3AE]">
        @include('components.header')
    </div>

    <div class="bg-white border-b">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <a href="/blog" class="inline-flex items-center text-gray-600 hover:text-gray-900 mb-4">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Back to Blog
            </a>

            <div class="flex items-center gap-2 mb-4">
                <span class="bg-[#FF9F40] text-white px-3 py-1 rounded-full text-sm font-medium">{{ $post['category'] }}</span>
            </div>

            <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-6">
                {{ $post['title'] }}
            </h1>

            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-[#2A73E8] rounded-full flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="font-medium text-gray-900">{{ $post['author'] }}</p>
                            <p class="text-sm text-gray-500">{{ $post['authorTitle'] }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 text-sm text-gray-500">
                        <div class="flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            {{ \Carbon\Carbon::parse($post['date'])->format('F d, Y') }}
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button onclick="shareArticle()" class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.367 2.684 3 3 0 00-5.367-2.684z"></path>
                        </svg>
                        Share
                    </button>
                    <button onclick="bookmarkArticle()" class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg text-sm font-medium transition-colors" title="Bookmark">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <img
                src="{{ $post['image'] }}"
                alt="{{ $post['title'] }}"
                class="w-full h-64 md:h-80 object-cover"
            />

            <div class="p-8 md:p-12">
                <div class="prose prose-lg max-w-none
                     [&_p]:text-gray-900 [&_p]:mb-4 [&_p]:leading-relaxed
                     [&_h1]:text-gray-900 [&_h1]:text-3xl [&_h1]:font-bold [&_h1]:mt-8 [&_h1]:mb-4
                     [&_h2]:text-gray-900 [&_h2]:text-2xl [&_h2]:font-bold [&_h2]:mt-8 [&_h2]:mb-4
                     [&_h3]:text-gray-900 [&_h3]:text-xl [&_h3]:font-semibold [&_h3]:mt-6 [&_h3]:mb-3
                     [&_h4]:text-gray-900 [&_h4]:text-lg [&_h4]:font-semibold [&_h4]:mt-4 [&_h4]:mb-2
                     [&_a]:text-blue-600 [&_a]:underline [&_a:hover]:text-blue-800
                     [&_strong]:text-gray-900 [&_strong]:font-bold
                     [&_em]:italic
                     [&_ul]:text-gray-900 [&_ul]:list-disc [&_ul]:pl-6 [&_ul]:mb-4 [&_ul]:space-y-2
                     [&_ol]:text-gray-900 [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:mb-4 [&_ol]:space-y-2
                     [&_li]:text-gray-900
                     [&_blockquote]:border-l-4 [&_blockquote]:border-[#2A73E8] [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-gray-700 [&_blockquote]:bg-gray-50 [&_blockquote]:py-2 [&_blockquote]:my-4
                     [&_code]:text-gray-900 [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:rounded
                     [&_pre]:text-gray-900 [&_pre]:bg-gray-100 [&_pre]:p-4 [&_pre]:rounded [&_pre]:overflow-x-auto [&_pre]:my-4
                     [&_img]:rounded-lg [&_img]:shadow-md [&_img]:my-4
                     [&_table]:w-full [&_table]:border-collapse [&_table]:my-4
                     [&_th]:border [&_th]:border-gray-300 [&_th]:bg-gray-100 [&_th]:px-4 [&_th]:py-2 [&_th]:text-left [&_th]:font-semibold
                     [&_td]:border [&_td]:border-gray-300 [&_td]:px-4 [&_td]:py-2
                     [&_hr]:border-gray-300 [&_hr]:my-8">
                    <p class="text-lg text-gray-700">{{ $post['excerpt'] }}</p>
                </div>
            </div>
        </div>

    </div>
</div>
@else
<!-- 404 for blog post not found -->
<div class="min-h-screen bg-gray-50 flex items-center justify-center">
    <div class="text-center">
        <h1 class="text-4xl font-bold text-gray-900 mb-4">Blog Post Not Found</h1>
        <p class="text-lg text-gray-600 mb-8">The blog post you're looking for doesn't exist.</p>
        <a href="/blog" class="bg-[#2A73E8] hover:bg-[#1557b0] text-white px-6 py-3 rounded-lg font-semibold transition-colors">
            Back to Blog
        </a>
    </div>
</div>
@endif
@endsection

<script>
function shareArticle() {
    if (navigator.share) {
        navigator.share({
            title: '{{ $post["title"] }}',
            text: '{{ $post["excerpt"] }}',
            url: window.location.href
        });
    } else {
        // Fallback: copy to clipboard
        navigator.clipboard.writeText(window.location.href).then(() => {
            alert('Link copied to clipboard!');
        });
    }
}

function bookmarkArticle() {
    // Simple bookmark functionality
    const bookmarks = JSON.parse(localStorage.getItem('blogBookmarks') || '[]');
    const articleId = '{{ $post["slug"] ?? "" }}';
    
    if (bookmarks.includes(articleId)) {
        bookmarks.splice(bookmarks.indexOf(articleId), 1);
        alert('Article removed from bookmarks');
    } else {
        bookmarks.push(articleId);
        alert('Article bookmarked!');
    }
    
    localStorage.setItem('blogBookmarks', JSON.stringify(bookmarks));
}
</script>






