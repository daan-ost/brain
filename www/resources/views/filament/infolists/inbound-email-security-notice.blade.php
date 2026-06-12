<div class="rounded-lg bg-warning-50 p-4 dark:bg-warning-900/20">
    <div class="flex">
        <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-warning-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
            </svg>
        </div>
        <div class="ml-3">
            <h3 class="text-sm font-medium text-warning-800 dark:text-warning-200">
                Encrypted Fields (Not Visible)
            </h3>
            <div class="mt-2 text-sm text-warning-700 dark:text-warning-300">
                <ul class="list-disc space-y-1 pl-5">
                    <li><strong>from_email</strong> - Sender email address</li>
                    <li><strong>from_name</strong> - Sender name</li>
                    <li><strong>subject</strong> - Email subject</li>
                    <li><strong>body_text</strong> - Plain text body</li>
                    <li><strong>body_html</strong> - HTML body</li>
                    <li><strong>headers</strong> - Full email headers</li>
                </ul>
            </div>
            <div class="mt-3 text-xs text-warning-600 dark:text-warning-400">
                These fields are encrypted at rest and can only be decrypted during processing. This protects user privacy in case of unauthorized admin access.
            </div>
        </div>
    </div>
</div>
