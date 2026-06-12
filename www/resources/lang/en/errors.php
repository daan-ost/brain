<?php

return [
    // ConvertAPI Service Errors
    'conversion_service_unavailable' => 'Service Temporarily Unavailable',
    'conversion_service_unavailable_message' => 'We\'re experiencing technical difficulties with our conversion service. Please try again in a few minutes or contact support if the problem persists.',

    'conversion_service_error' => 'Conversion Service Error',
    'conversion_service_error_message' => 'Our conversion service is temporarily unavailable. Please try again shortly.',

    'conversion_server_error' => 'Server Error',
    'conversion_server_error_message' => 'The conversion service encountered an error. Please try again.',

    'conversion_timeout' => 'Processing Timeout',
    'conversion_timeout_message' => 'Your file is taking longer to process than expected. For large files, please try again or contact support.',

    'conversion_rate_limit' => 'Service Busy',
    'conversion_rate_limit_message' => 'Our service is currently handling many requests. Please wait a moment and try again.',

    'conversion_failed' => 'Conversion Failed',
    'conversion_failed_message' => 'We couldn\'t process your file. Please check the file and try again, or contact support.',

    // Specific ConvertAPI Errors
    'pdf_no_tables' => 'No Tables Found in PDF',
    'pdf_no_tables_message' => 'This PDF doesn\'t contain any tables to extract. The PDF-to-Excel conversion only works with PDFs that have table data.',

    // File Limit Errors
    'file_limit_exceeded' => 'File Limit Exceeded',
    'file_limit_exceeded_message' => 'Your plan allows :limit files. You selected :count files.',
    'file_limit_remove_files' => 'Remove :excess file(s) to continue.',

    'zip_too_many_files' => 'ZIP Contains Too Many Files',
    'zip_too_many_files_message' => 'This ZIP contains :count files but your plan allows :limit files.',
    'zip_rejection_split' => 'Split into smaller ZIPs (max :limit files each)',
    'zip_rejection_upgrade' => 'Upgrade to Business plan (:business_limit files)',
    'zip_rejection_remove' => 'Remove some files before zipping',

    'multiple_zips_exceeded' => 'Multiple ZIPs Exceed Limit',
    'multiple_zips_exceeded_message' => 'Combined, your ZIPs contain :count files. Your plan allows :limit files.',

    'file_count_status' => ':current / :limit files',
    'files_remaining' => ':remaining files remaining',

    'zip_open_failed' => 'Failed to open ZIP file',

    // PDF Merge Errors
    'pdf_merge_failed' => 'PDF Merge Failed',
    'pdf_merge_failed_message' => 'Unable to merge PDFs: :error',
    'pdf_corrupt' => 'Corrupt PDF File',
    'pdf_corrupt_message' => 'One or more PDF files are corrupt or invalid. Please check your files and try again.',
    'pdf_header_not_found' => 'Unable to find PDF file header',
];
