<?php

namespace App\Listeners;

use App\Services\OrganizationAutoEnrollmentService;
use Illuminate\Auth\Events\Verified;

/**
 * Auto-enroll users into organizations when they verify their email
 *
 * This listener delegates enrollment logic to OrganizationAutoEnrollmentService.
 * It fires on the Verified event for edge cases not handled directly in controllers.
 *
 * Note: Most email verifications now handle enrollment inline in EmailConfirmationController
 * to provide immediate feedback to users. This listener serves as a fallback for other
 * verification scenarios.
 *
 * Related:
 * - Service: App\Services\OrganizationAutoEnrollmentService
 * - Controller: EmailConfirmationController::confirm()
 * - Manual domain validation: beheer/organization-domain
 * - Gap tests: tests/Feature/Organization/AutoEnrollmentGapsTest.php
 * - Documentation: /docs/todo_0_autoenrollment.md
 */
class AutoEnrollUserInOrganization
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private OrganizationAutoEnrollmentService $enrollmentService
    ) {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(Verified $event): void
    {
        $user = $event->user;

        // Delegate to service
        $this->enrollmentService->enrollUser($user);
    }
}
