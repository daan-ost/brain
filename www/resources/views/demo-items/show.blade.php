@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        @livewire('demo-items.show', ['demoItem' => $demoItem])
    </div>
</div>
@endsection
