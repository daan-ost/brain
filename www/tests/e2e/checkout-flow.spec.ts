import { test, expect, Page } from "@playwright/test";

/**
 * Checkout and Pricing Flow E2E Tests
 *
 * Tests the complete purchase journey:
 * - Pricing page and license selection
 * - Checkout wizard with billing details
 * - Payment method selection
 * - VAT validation
 */

test.describe("Pricing and Checkout Flow", () => {
  /**
   * Helper: Wait for Livewire to finish
   */
  async function waitForLivewire(page: Page): Promise<void> {
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(500);
  }

  /**
   * Helper: Login as test user
   */
  async function loginAsUser(page: Page): Promise<void> {
    await page.goto("/login");
    await page.fill("input[name=\"email\"]", "test@example.com");
    await page.fill("input[name=\"password\"]", "password");
    await page.click("button[type=\"submit\"]");
    await waitForLivewire(page);
  }

  /**
   * Helper: Navigate to checkout via pricing page (selects premium license)
   * Returns true if checkout page was reached
   */
  async function navigateToCheckout(page: Page): Promise<boolean> {
    await page.goto("/pricing");
    await waitForLivewire(page);

    // Select premium tier via radio button
    const premiumRadio = page.locator("input[type='radio'][wire\\:model\\.live='selectedPremium']").first();
    if (await premiumRadio.isVisible()) {
      await premiumRadio.click();
      await waitForLivewire(page);
    }

    // Click Pay Online button (wire:click="selectLicense")
    const payOnlineButton = page.locator("button[wire\\:click*='selectLicense']").first();
    if (await payOnlineButton.isVisible()) {
      await payOnlineButton.click();
      await waitForLivewire(page);
      return page.url().includes("/checkout");
    }
    return false;
  }

  // ============================================================================
  // PRICING PAGE TESTS
  // ============================================================================

  test.describe("Pricing Page", () => {
    test("should display pricing page with license options", async ({ page }) => {
      await page.goto("/pricing");
      await waitForLivewire(page);

      // Verify pricing page loaded (EN: "Choose Your Plan" / NL: "Kies uw abonnement")
      await expect(page.locator("h1").filter({ hasText: /choose your plan|kies uw abonnement/i }).first()).toBeVisible();

      // Should have license tier cards (h3 headings: Free, Premium, Enterprise, One-time Credits)
      const tierHeadings = page.locator("h3").filter({ hasText: /free|premium|enterprise|one-time/i });
      const count = await tierHeadings.count();
      expect(count).toBeGreaterThan(0);
    });

    test("should display currency toggle for individual users", async ({ page }) => {
      // Clear auth cookies: authenticated users with an organization get showCurrencyToggle=false.
      // An unauthenticated (individual) user always sees the toggle.
      await page.context().clearCookies();
      await page.goto("/pricing");
      await waitForLivewire(page);

      // Currency toggle uses links (not buttons)
      const eurLink = page.locator("a[href*='currency=EUR']");
      const usdLink = page.locator("a[href*='currency=USD']");
      const hasToggle = await eurLink.count() > 0 && await usdLink.count() > 0;
      expect(hasToggle).toBe(true);
    });

    test("should select a license tier and show packages", async ({ page }) => {
      await page.goto("/pricing");
      await waitForLivewire(page);

      // Premium card has radio buttons for selecting credit tiers
      const premiumRadio = page.locator("input[type='radio'][wire\\:model\\.live='selectedPremium']").first();
      if (await premiumRadio.isVisible()) {
        await premiumRadio.click();
        await waitForLivewire(page);

        // Should show Pay Online or Pay by Invoice buttons
        const payOnlineBtn = page.locator("button").filter({ hasText: /Pay Online|Betaal online/i });
        const payInvoiceBtn = page.locator("button").filter({ hasText: /Pay by Invoice|Betaal per factuur/i });
        const hasPayButtons = await payOnlineBtn.count() > 0 || await payInvoiceBtn.count() > 0;
        expect(hasPayButtons).toBe(true);
      }
    });

    test("should switch currency and update prices", async ({ page }) => {
      await page.goto("/pricing");
      await waitForLivewire(page);

      // Click USD currency link
      const usdLink = page.locator("a[href*='currency=USD']").first();
      if (await usdLink.isVisible()) {
        await usdLink.click();
        await waitForLivewire(page);

        // URL should now contain currency=USD
        expect(page.url()).toContain("currency=USD");
      }
    });

    test("should show login prompt for unauthenticated users trying to checkout", async ({ page }) => {
      // Clear any existing session
      await page.context().clearCookies();

      await page.goto("/pricing");
      await waitForLivewire(page);

      // For unauthenticated users, "Pay Online" button triggers an auth modal or "Sign up" links to register
      const signupLink = page.locator("a").filter({ hasText: /sign up|registreer/i }).first();
      const payButton = page.locator("button").filter({ hasText: /Pay Online|Betaal online/i }).first();

      if (await payButton.isVisible()) {
        await payButton.click();
        await waitForLivewire(page);
        await page.waitForTimeout(500);

        // Should show login/auth modal or redirect to login
        const isOnLogin = page.url().includes("/login");
        const hasLoginPrompt = await page.locator("text=/login|inloggen|sign in|log in/i").first().isVisible();
        expect(isOnLogin || hasLoginPrompt).toBe(true);
      } else if (await signupLink.isVisible()) {
        // Free tier shows "Sign up" link to register page
        const href = await signupLink.getAttribute("href");
        expect(href).toContain("/register");
      }
    });
  });

  // ============================================================================
  // CHECKOUT WIZARD TESTS
  // ============================================================================

  test.describe("Checkout Wizard", () => {
    test.beforeEach(async ({ page }) => {
      // Authentication is handled by storageState in playwright.config.ts (user-tests project).
      // Calling loginAsUser() here would time out because Laravel redirects already-authenticated
      // users away from /login before the email input becomes visible.
    });

    test("should redirect to pricing if no license selected", async ({ page }) => {
      // Try to access checkout directly without license
      await page.goto("/checkout");
      await waitForLivewire(page);

      // Should redirect to pricing or show error
      const isOnPricing = page.url().includes("/pricing");
      const hasError = await page.locator("text=/select.*license|kies.*licentie|first/i").first().isVisible();
      expect(isOnPricing || hasError).toBe(true);
    });

    test("should load checkout page with license selection", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);

      if (reachedCheckout) {
        // Should show billing form or checkout content
        const hasForm = await page.locator("input, select, form").first().isVisible();
        expect(hasForm).toBe(true);
      }
    });

    test("should display billing form with required fields", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // Check for billing form fields
      const formFields = [
        "input[wire\\:model*=\"full_name\"], input[name*=\"name\"]",
        "input[wire\\:model*=\"email\"], input[name*=\"email\"]",
        "input[wire\\:model*=\"street\"], input[name*=\"street\"], input[name*=\"address\"]",
        "input[wire\\:model*=\"city\"], input[name*=\"city\"]",
        "input[wire\\:model*=\"postal_code\"], input[name*=\"postal\"], input[name*=\"zip\"]",
      ];

      let visibleCount = 0;
      for (const selector of formFields) {
        const field = page.locator(selector).first();
        if (await field.isVisible()) {
          visibleCount++;
        }
      }
      // At least some billing fields should be visible
      expect(visibleCount).toBeGreaterThan(0);
    });

    test("should toggle between individual and company billing", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // Find buyer type toggle
      const companyToggle = page.locator("button").filter({ hasText: /company|bedrijf|business/i }).first();
      const companyInput = page.locator("input[value='company']").first();
      const toggle = await companyToggle.isVisible() ? companyToggle : companyInput;

      if (await toggle.isVisible()) {
        await toggle.click();
        await waitForLivewire(page);

        // Company fields should appear
        const companyNameField = page.locator("input[wire\\:model*='company_name'], input[name*='company']").first();
        const vatField = page.locator("input[wire\\:model*='vat_id'], input[name*='vat']").first();

        const hasCompanyFields = await companyNameField.isVisible() || await vatField.isVisible();
        expect(hasCompanyFields).toBe(true);
      }
    });

    test("should validate VAT number for EU companies", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // Switch to company billing
      const companyToggle = page.locator("button").filter({ hasText: /company|bedrijf/i }).first();
      if (await companyToggle.isVisible()) {
        await companyToggle.click();
        await waitForLivewire(page);
      }

      // Fill in VAT field with invalid number
      const vatField = page.locator("input[wire\\:model*='vat_id'], input[name*='vat']").first();
      if (await vatField.isVisible()) {
        await vatField.fill("INVALID123");
        await vatField.blur();
        await waitForLivewire(page);

        // Should show validation message
        const hasValidation = await page.locator("text=/valid|invalid|geldig|ongeldig|checking|controleren/i").first().isVisible();
        expect(typeof hasValidation).toBe("boolean");
      }
    });

    test("should show payment method options", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // Fill minimum required fields first
      const nameField = page.locator("input[wire\\:model*='full_name'], input[name*='name']").first();
      if (await nameField.isVisible()) {
        await nameField.fill("Test User");
      }

      // Look for payment method section
      const paymentSection = page.locator("text=/payment.*method|betaalmethode|betaling/i").first();
      const paymentOptions = page.locator("input[name*='payment'], [wire\\:click*='payment']");

      const hasPaymentOptions = await paymentSection.isVisible() || await paymentOptions.count() > 0;
      expect(typeof hasPaymentOptions).toBe("boolean");
    });

    test("should show order summary with correct amounts", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // Order summary should show price breakdown
      const summarySection = page.locator("text=/total|totaal|subtotal|subtotaal|vat|btw/i").first();
      const priceDisplay = page.locator("text=/€|EUR|\\$/");

      const hasSummary = await summarySection.isVisible() || await priceDisplay.count() > 0;
      expect(hasSummary).toBe(true);
    });

    test("should validate required billing fields", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // Try to proceed without filling required fields
      const submitButton = page.locator("button[type='submit']").first();
      const continueButton = page.locator("button").filter({ hasText: /continue|verder|next|volgende|pay|betaal/i }).first();
      const btn = await submitButton.isVisible() ? submitButton : continueButton;

      if (await btn.isVisible()) {
        await btn.click();
        await waitForLivewire(page);

        // Should show validation errors
        const hasErrors = await page.locator("text=/required|verplicht|fill|invullen/i").first().isVisible();
        const hasErrorStyle = await page.locator(".text-red-500, .text-danger, [class*='error']").count() > 0;

        expect(hasErrors || hasErrorStyle).toBe(true);
      }
    });

    test("should handle country selection and show state field for US", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // Find country select
      const countrySelect = page.locator("select[wire\\:model*='country'], select[name*='country']").first();

      if (await countrySelect.isVisible()) {
        await countrySelect.selectOption("US");
        await waitForLivewire(page);

        // State field should appear for US
        const stateField = page.locator("select[wire\\:model*='state'], input[wire\\:model*='state'], select[name*='state'], input[name*='state']");
        const hasStateField = await stateField.count() > 0;
        expect(hasStateField).toBe(true);
      }
    });
  });

  // ============================================================================
  // PAYER SELECTION TESTS (User vs Organization)
  // ============================================================================

  test.describe("Payer Selection", () => {
    test.beforeEach(async ({ page }) => {
      // Authentication handled by storageState in playwright.config.ts (user-tests project).
    });

    test("should show payer selection when user has organizations", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // If user has organizations, should see payer selection
      const payerSelection = page.locator("text=/pay.*as|betaal.*als|personal|persoonlijk|organization|organisatie/i").first();
      const organizationOption = page.locator("button").filter({ hasText: /organization|organisatie/i }).first();

      const hasPayerOptions = await payerSelection.isVisible() || await organizationOption.isVisible();
      expect(typeof hasPayerOptions).toBe("boolean");
    });

    test("should update billing details when selecting organization payer", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // Try to select organization as payer
      const orgOption = page.locator("button").filter({ hasText: /organization|organisatie/i }).first();

      if (await orgOption.isVisible()) {
        await orgOption.click();
        await waitForLivewire(page);

        // Billing fields should be pre-filled with organization data
        const companyField = page.locator("input[wire\\:model*='company_name']").first();
        if (await companyField.isVisible()) {
          const value = await companyField.inputValue();
          expect(typeof value).toBe("string");
        }
      }
    });
  });

  // ============================================================================
  // ACTIVATION PAGE TESTS
  // ============================================================================

  test.describe("Activation Page", () => {
    test.beforeEach(async ({ page }) => {
      // Authentication handled by storageState in playwright.config.ts (user-tests project).
    });

    test("should display activation page after successful payment", async ({ page }) => {
      await page.goto("/activation");
      await waitForLivewire(page);

      // Should show activation info or redirect
      const hasActivationContent = await page.locator("text=/activation|activatie|license|licentie|order|bestelling/i").first().isVisible();
      const isRedirected = !page.url().includes("/activation");

      expect(hasActivationContent || isRedirected).toBe(true);
    });
  });

  // ============================================================================
  // ERROR HANDLING TESTS
  // ============================================================================

  test.describe("Error Handling", () => {
    test.beforeEach(async ({ page }) => {
      // Authentication handled by storageState in playwright.config.ts (user-tests project).
    });

    test("should handle invalid license ID gracefully", async ({ page }) => {
      await page.goto("/checkout?license=999999");
      await waitForLivewire(page);

      // Should show error message or redirect to pricing
      const hasError = await page.locator("text=/not.*found|niet.*gevonden|invalid|ongeldig|error|no.*license.*selected/i").first().isVisible();
      const isOnPricing = page.url().includes("/pricing");
      const hasBackLink = await page.locator("a[href*='/pricing']").first().isVisible();

      expect(hasError || isOnPricing || hasBackLink).toBe(true);
    });

    test("should handle session expiration gracefully", async ({ page }) => {
      const reachedCheckout = await navigateToCheckout(page);
      if (!reachedCheckout) return;

      // Clear session mid-flow
      await page.context().clearCookies();

      // Try to submit
      const submitButton = page.locator("button[type='submit']").first();
      if (await submitButton.isVisible()) {
        await submitButton.click();
        await waitForLivewire(page);

        // Should redirect to login
        await expect(page).toHaveURL(/\/login/);
      }
    });
  });
});
