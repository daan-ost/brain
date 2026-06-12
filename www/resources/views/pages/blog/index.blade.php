@extends('layouts.app')

@section('content')
@php
$currentLocale = app()->getLocale();
$urlPrefix = $currentLocale === 'nl' ? '/nl' : '';

// Use the entries passed from route (always an empty collection for now)
$blogPosts = [];
$categories = ['All'];
@endphp

<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-gradient-to-br from-[#9FD6D2] to-[#53B3AE] text-white">
        @include('components.header')

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
            <div class="text-center">
                <h1 class="text-4xl md:text-5xl font-bold mb-6">{{ config('app.name') }} Blog</h1>
                <p class="text-xl text-white/90 max-w-3xl mx-auto">
                    Insights, tutorials, and best practices for modern document workflows
                </p>
            </div>
        </div>
    </div>

    <!-- Search and Filter -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex flex-col md:flex-row gap-4 items-center justify-between mb-8">
            <div class="relative flex-1 max-w-md">
                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input 
                    id="searchInput" 
                    placeholder="Search articles..." 
                    class="pl-10 pr-4 py-2 w-full border border-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-black" 
                />
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($categories as $category)
                <button 
                    onclick="filterByCategory('{{ $category }}')" 
                    class="category-btn px-4 py-2 text-sm font-medium rounded-md transition-colors {{ $category === 'All' ? 'bg-[#2A73E8] text-white hover:bg-[#1557b0]' : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50' }}"
                    data-category="{{ $category }}"
                >
                    {{ $category }}
                </button>
                @endforeach
            </div>
        </div>

        <!-- Featured Post -->
        @foreach($blogPosts as $post)
            @if($post['featured'])
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden mb-12">
                <div class="md:flex">
                    <div class="md:w-1/2">
                        <a href="{{ $post['url'] }}" class="block">
                            <img
                                src="{{ $post['image'] ?? '/placeholder.svg' }}"
                                alt="{{ $post['title'] }}"
                                class="w-full h-64 md:h-full object-cover hover:opacity-90 transition-opacity"
                            />
                        </a>
                    </div>
                    <div class="md:w-1/2 p-8">
                        <div class="flex items-center gap-4 mb-4">
                            <span class="bg-[#FF9F40] text-white px-3 py-1 rounded-full text-sm font-medium">Featured</span>
                            <span class="bg-gray-100 text-gray-700 px-3 py-1 rounded-full text-sm">{{ $post['category'] }}</span>
                        </div>
                        <h2 class="text-2xl md:text-3xl font-bold text-gray-900 mb-4">
                            <a href="{{ $post['url'] }}" class="hover:text-blue-600 transition-colors">{{ $post['title'] }}</a>
                        </h2>
                        <p class="text-gray-600 mb-6 text-lg">{{ $post['excerpt'] }}</p>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4 text-sm text-gray-500">
                                <span>{{ $post['author'] }}</span>
                                <div class="flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                    {{ \Carbon\Carbon::parse($post['date'])->format('M d, Y') }}
                                </div>
                            </div>
                            <a href="{{ $post['url'] }}" class="bg-[#2A73E8] hover:bg-[#1557b0] text-white px-4 py-2 rounded-lg font-semibold transition-colors flex items-center">
                                Read More
                                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        @endforeach

        <!-- Blog Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8" id="blogGrid">
            @foreach($blogPosts as $post)
                @if(!$post['featured'])
                <article class="blog-post bg-white rounded-2xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow" data-category="{{ $post['category'] }}" data-title="{{ strtolower($post['title']) }}" data-excerpt="{{ strtolower($post['excerpt']) }}">
                    <a href="{{ $post['url'] }}" class="block">
                        <img src="{{ $post['image'] ?? '/placeholder.svg' }}" alt="{{ $post['title'] }}" class="w-full h-48 object-cover hover:opacity-90 transition-opacity" />
                    </a>
                    <div class="p-6">
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                            </svg>
                            <span class="text-sm text-gray-500">{{ $post['category'] }}</span>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-3 line-clamp-2">
                            <a href="{{ $post['url'] }}" class="hover:text-blue-600 transition-colors">{{ $post['title'] }}</a>
                        </h3>
                        <p class="text-gray-600 mb-4 line-clamp-3">{{ $post['excerpt'] }}</p>
                        <div class="flex items-center justify-between text-sm text-gray-500 mb-4">
                            <span>{{ $post['author'] }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500">{{ \Carbon\Carbon::parse($post['date'])->format('M d, Y') }}</span>
                            <a href="{{ $post['url'] }}" class="bg-[#2A73E8] hover:bg-[#1557b0] text-white px-3 py-2 rounded-lg font-semibold text-sm transition-colors flex items-center">
                                Read More
                                <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                </article>
                @endif
            @endforeach
        </div>

        <!-- Load More -->
        @if(count($blogPosts) > 6)
        <div class="text-center mt-12">
            <button id="loadMoreBtn" class="border-2 border-gray-300 text-gray-700 hover:bg-gray-50 px-6 py-3 rounded-lg font-semibold transition-colors">
                Load More Articles
            </button>
        </div>
        @endif
    </div>
</div>
@endsection

<script>
console.log('Blog JavaScript loading...');

let currentCategory = 'All';
let currentSearch = '';

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing blog functionality...');
    
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        console.log('Search input found, adding event listener...');
        searchInput.addEventListener('input', function(e) {
            console.log('Search input changed:', e.target.value);
            currentSearch = e.target.value.toLowerCase();
            filterPosts();
        });
    } else {
        console.error('Search input not found!');
    }
    
    // Initialize filtering
    filterPosts();
});

// Category filtering
function filterByCategory(category) {
    console.log('Filtering by category:', category);
    currentCategory = category;
    
    // Update button styles
    document.querySelectorAll('.category-btn').forEach(btn => {
        btn.classList.remove('bg-[#2A73E8]', 'text-white', 'hover:bg-[#1557b0]');
        btn.classList.add('bg-white', 'text-gray-700', 'border', 'border-gray-300', 'hover:bg-gray-50');
    });
    
    // Highlight selected button
    const selectedBtn = document.querySelector(`[data-category="${category}"]`);
    if (selectedBtn) {
        selectedBtn.classList.remove('bg-white', 'text-gray-700', 'border', 'border-gray-300', 'hover:bg-gray-50');
        selectedBtn.classList.add('bg-[#2A73E8]', 'text-white', 'hover:bg-[#1557b0]');
    }
    
    filterPosts();
}

// Filter posts based on category and search
function filterPosts() {
    console.log('Filtering posts...', 'Category:', currentCategory, 'Search:', currentSearch);
    const posts = document.querySelectorAll('.blog-post');
    console.log('Found posts:', posts.length);
    
    let visibleCount = 0;
    
    posts.forEach((post, index) => {
        const category = post.getAttribute('data-category');
        const title = post.getAttribute('data-title');
        const excerpt = post.getAttribute('data-excerpt');
        
        const matchesCategory = currentCategory === 'All' || category === currentCategory;
        const matchesSearch = currentSearch === '' || 
            title.includes(currentSearch) || 
            excerpt.includes(currentSearch);
        
        console.log(`Post ${index}:`, {
            category,
            title: title.substring(0, 50) + '...',
            matchesCategory,
            matchesSearch
        });
        
        if (matchesCategory && matchesSearch) {
            post.style.display = 'block';
            visibleCount++;
        } else {
            post.style.display = 'none';
        }
    });
    
    console.log('Visible posts:', visibleCount);
    
    // Show/hide Load More button based on visible posts
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    if (loadMoreBtn) {
        loadMoreBtn.style.display = visibleCount > 6 ? 'block' : 'none';
    }
}

// Load More functionality (placeholder - would need backend integration)
document.addEventListener('DOMContentLoaded', function() {
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function() {
            alert('Load More functionality would require backend integration to fetch more posts.');
        });
    }
});
</script>






