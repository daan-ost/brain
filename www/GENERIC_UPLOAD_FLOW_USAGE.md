# Generic 3-Step Upload Flow Component

A reusable 3-step upload flow component that can be used across different landing pages (e.g., `/pdf-to-images`, `/images-to-pdf`, etc.).

## Features

### Step 1 — Upload
- Drag-and-drop or file selection
- Automatic file type validation based on landing page configuration
- File size and count limits enforcement
- Visual feedback with allowed file types display
- Security and compliance badges

### Step 2 — Specify
- Display list of uploaded files with remove functionality
- Workflow-specific configuration options
- Credits validation and display
- Convert button with validation

### Step 3 — Convert
- Real-time progress tracking (pending → processing → done/error)
- Download link when conversion is complete
- Credits deduction and balance updates
- Error handling with retry functionality

## Integration

### Basic Usage

```blade
@include('components.generic-upload-flow', [
    'pageSlug' => 'images-to-pdf',
    'pageConfig' => config('landing_pages.images-to-pdf')
])
```

### Complete Landing Page Example

```blade
@extends('layouts.app')

@php
    $pageSlug = 'images-to-pdf'; // or extract from route
    $pageConfig = config("landing_pages.{$pageSlug}");
@endphp

@section('content')
<div class="min-h-screen">
    <section class="masthead" style="background: linear-gradient(135deg, #9FD6D2 0%, #53B3AE 100%);">
        @include('components.header')

        <!-- Hero content -->
        <div class="container mx-auto px-4 py-16 text-center">
            <h1 class="text-4xl font-bold text-white">{{ $pageConfig['title'] }}</h1>
            <p class="text-white/90">{{ $pageConfig['description'] }}</p>
        </div>

        <!-- Upload Flow Component -->
        @include('components.generic-upload-flow', compact('pageSlug', 'pageConfig'))
    </section>
</div>
@endsection
```

## Configuration

The component automatically reads configuration from `config/landing_pages.php`:

### Required Configuration

```php
'images-to-pdf' => [
    'slug' => 'images-to-pdf',
    'title' => 'Merge images to PDF',
    'description' => 'Upload multiple images and convert them to a single PDF file.',
    'action_type' => 'merge', // or 'convert'
    'workflow_preset' => 'images_to_pdf__download',
    'allowed_mime_groups' => ['images', 'zips'],
    'limits' => [
        'max_files' => 20,
        'max_total_size' => 200 * 1024 * 1024, // 200MB
    ],
    'cta_label' => 'Convert images',
    'copy' => [
        'intro_text' => 'Upload multiple image files...',
        'error_text' => 'Please select valid image files.',
        'success_text' => 'Your images have been converted successfully!',
    ],
]
```

### Optional Configuration

```php
'options' => [
    'image_format' => 'jpg',
    'resolution' => '150',
    'page_size' => 'A4',
    'orientation' => 'portrait'
],
'reorder_enabled' => true,
'preview_type' => 'thumbnails', // or 'icons_only'
'output_mode' => 'single_pdf', // or 'individual_files'
```

## Workflow Integration

The component automatically:

1. **Detects workflow** based on `pageSlug` using `Workflow::getDefaultForLandingPage()`
2. **Validates file types** using `PageConfigProvider->validateFile()`
3. **Calculates credits** using `CreditsService->validateAndReserveCredits()`
4. **Executes workflow** using `WorkflowRunner->executeWorkflow()`
5. **Tracks progress** via `WorkflowExecution` model

## Database Integration

### File Storage
- Files stored in `file_uploads` table
- Linked to user or guest session
- Status tracking: uploaded → processing → done/error

### Credits System
- Automatic credit validation before conversion
- Credit deduction after successful conversion
- Support for both personal and organization credits

### Workflow Execution
- Creates `workflow_executions` record
- Real-time status tracking
- Result file storage and download

## API Endpoints

The component uses these API endpoints:

- `GET /api/user/credits` - Get current user credits
- `POST /api/validate-credits` - Validate credits for conversion
- `POST /api/upload-and-convert` - Upload files and start conversion
- `GET /api/workflow-execution/{id}/status` - Check conversion status
- `GET /download/workflow-result/{id}` - Download result file

## Testing

### Test Route
Visit `/test-upload-flow/images-to-pdf` to test the component with different page configurations.

### Available Test Pages
- `/test-upload-flow/images-to-pdf`
- `/test-upload-flow/doc-to-pdf`
- `/test-upload-flow/excel-to-pdf`
- `/test-upload-flow/pdf-to-images`

## Error Handling

The component handles:
- Invalid file types
- File size limits
- Credit validation failures
- Workflow execution errors
- Network connectivity issues

## Security Features

- File type validation
- File size limits
- Guest session isolation
- User permission checks
- ZIP slip protection (when extracting archives)

## Dependencies

- **Alpine.js 3.x** - For reactive UI behavior
- **Laravel 11** - Backend framework
- **Existing services**: `PageConfigProvider`, `CreditsService`, `WorkflowRunner`

## Browser Compatibility

- Modern browsers with ES6 support
- File drag-and-drop API support
- Fetch API support

## Customization

### Styling
The component uses Tailwind CSS classes and can be customized by modifying the component template.

### Behavior
Component behavior can be modified by extending the Alpine.js `uploadFlow()` function.

### Configuration Options
Add new configuration options to `config/landing_pages.php` and handle them in the component logic.