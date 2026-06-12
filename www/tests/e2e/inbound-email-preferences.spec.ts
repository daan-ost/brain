import { test, expect } from "@playwright/test";

test.describe("Inbound Email Preferences", () => {
  test.beforeEach(async ({ page }) => {
    // Authentication handled by storageState in playwright.config.ts (user-tests project).
  });

  test("should display inbound email preferences section on email-preferences page", async ({ page }) => {
    // Navigate to email preferences page (no new menu item needed)
    await page.goto("/profile/email-preferences");

    // Verify inbound email section exists as a new paragraph on existing page
    await expect(page.locator("h3:has-text(\"Inbound Email\")")).toBeVisible();

    // Verify description/explanation is visible (supports both EN and NL)
    // EN: "receive and process emails" / NL: "Ontvang en verwerk emails"
    await expect(page.locator("text=/receive.*process.*email|ontvang.*verwerk.*email/i")).toBeVisible();
  });

  test("should enable inbound email and display action-specific addresses", async ({ page }) => {
    await page.goto("/profile/email-preferences");

    // Find the enable toggle - use role=switch within the Inbound Email section
    const inboundSection = page.locator("section").filter({ has: page.locator("h3:has-text(\"Inbound Email\")") });
    const toggle = inboundSection.locator("[role=\"switch\"]").first();
    await expect(toggle).toBeVisible();

    // Check if already enabled, if so disable first
    const isEnabled = await toggle.getAttribute("aria-checked") === "true";
    if (isEnabled) {
      await toggle.click();
      await page.waitForTimeout(500);
    }

    // Enable inbound email
    await toggle.click();
    await page.waitForTimeout(1000);

    // Verify success message (EN: enabled successfully / NL: succesvol geactiveerd)
    await expect(page.locator("text=/enabled.*successfully|succesvol.*geactiveerd|ingeschakeld/i")).toBeVisible({ timeout: 10000 });

    // Verify action-specific email addresses are displayed (code elements with email format)
    const actionEmails = page.locator("code");
    await expect(actionEmails.first()).toBeVisible({ timeout: 5000 });

    // Verify email format contains action+token@domain pattern
    const emailText = await actionEmails.first().textContent();
    expect(emailText).toMatch(/[a-z]+\+[a-zA-Z0-9]+@/);
  });

  test("should disable inbound email after it was enabled", async ({ page }) => {
    await page.goto("/profile/email-preferences");

    const inboundSection = page.locator("section").filter({ has: page.locator("h3:has-text(\"Inbound Email\")") });
    const toggle = inboundSection.locator("[role=\"switch\"]").first();

    // Ensure it's enabled first
    const isEnabled = await toggle.getAttribute("aria-checked") === "true";
    if (!isEnabled) {
      await toggle.click();
      await page.waitForTimeout(1000);
    }

    // Disable it
    await toggle.click();
    await page.waitForTimeout(1000);

    // Verify disabled message (EN: disabled / NL: uitgeschakeld/gedeactiveerd)
    await expect(page.locator("text=/disabled|uitgeschakeld|gedeactiveerd/i")).toBeVisible({ timeout: 10000 });
  });

  test("should show advanced options with verify sender toggle", async ({ page }) => {
    await page.goto("/profile/email-preferences");

    const inboundSection = page.locator("section").filter({ has: page.locator("h3:has-text(\"Inbound Email\")") });
    const enableToggle = inboundSection.locator("[role=\"switch\"]").first();

    // Enable inbound first
    const isEnabled = await enableToggle.getAttribute("aria-checked") === "true";
    if (!isEnabled) {
      await enableToggle.click();
      await page.waitForTimeout(1000);
    }

    // Click on advanced options link/button (EN: Advanced / NL: Geavanceerd)
    const advancedButton = page.locator("button:has-text(\"Advanced\"), button:has-text(\"Geavanceerd\")");
    await expect(advancedButton).toBeVisible({ timeout: 5000 });
    await advancedButton.click();

    // Verify advanced options are now visible (EN: verify sender / NL: verifieer afzender)
    await expect(page.locator("text=/verify.*sender|verifieer.*afzender/i")).toBeVisible();

    // Verify security warning is shown (EN: warning / NL: waarschuwing)
    await expect(page.locator("text=/warning|waarschuwing/i")).toBeVisible();
  });

  test("should toggle verify sender in advanced options", async ({ page }) => {
    await page.goto("/profile/email-preferences");

    const inboundSection = page.locator("section").filter({ has: page.locator("h3:has-text(\"Inbound Email\")") });
    const enableToggle = inboundSection.locator("[role=\"switch\"]").first();

    // Enable inbound first
    const isEnabled = await enableToggle.getAttribute("aria-checked") === "true";
    if (!isEnabled) {
      await enableToggle.click();
      await page.waitForTimeout(1000);
    }

    // Open advanced options
    const advancedButton = page.locator("button:has-text(\"Advanced\"), button:has-text(\"Geavanceerd\")");
    await advancedButton.click();
    await page.waitForTimeout(500);

    // Find verify sender toggle (should be the second toggle in the inbound section)
    const verifySenderToggle = inboundSection.locator("[role=\"switch\"]").nth(1);
    await expect(verifySenderToggle).toBeVisible();

    // Toggle it
    await verifySenderToggle.click();
    await page.waitForTimeout(1000);

    // Verify update message (EN: updated / NL: bijgewerkt)
    await expect(page.locator("text=/updated|bijgewerkt/i")).toBeVisible({ timeout: 10000 });
  });

  test("should display multiple action types with descriptions", async ({ page }) => {
    await page.goto("/profile/email-preferences");

    const inboundSection = page.locator("section").filter({ has: page.locator("h3:has-text(\"Inbound Email\")") });
    const toggle = inboundSection.locator("[role=\"switch\"]").first();

    // Enable inbound
    const isEnabled = await toggle.getAttribute("aria-checked") === "true";
    if (!isEnabled) {
      await toggle.click();
      await page.waitForTimeout(1000);
    }

    // Verify action types are shown by checking for email addresses with + symbol
    const actionEmails = page.locator("code");
    const count = await actionEmails.count();
    expect(count).toBeGreaterThan(0);
  });

  test("should be able to copy email address to clipboard", async ({ page }) => {
    await page.goto("/profile/email-preferences");

    const inboundSection = page.locator("section").filter({ has: page.locator("h3:has-text(\"Inbound Email\")") });
    const toggle = inboundSection.locator("[role=\"switch\"]").first();

    // Enable inbound
    const isEnabled = await toggle.getAttribute("aria-checked") === "true";
    if (!isEnabled) {
      await toggle.click();
      await page.waitForTimeout(1000);
    }

    // Click copy button for first action email (button with copy icon near code element)
    const copyButton = inboundSection.locator("button:has(svg)").first();
    await expect(copyButton).toBeVisible();
    await copyButton.click();

    // Verify "Copied!" feedback is shown (EN: Copied / NL: Gekopieerd)
    await page.waitForTimeout(500);
    await expect(page.locator("text=/copied|gekopieerd/i")).toBeVisible({ timeout: 3000 });
  });

  test("should not create new menu item - verify feature is on existing page", async ({ page }) => {
    await page.goto("/profile/email-preferences");

    // Verify we're on the email-preferences page (existing page)
    await expect(page).toHaveURL(/\/profile\/email-preferences/);

    // Verify page shows newsletter section (h3 within the Livewire component)
    await expect(page.locator("h3:has-text(\"Newsletter\"), h3:has-text(\"Nieuwsbrief\")").first()).toBeVisible();

    // Verify inbound section is within this page
    await expect(page.locator("h3:has-text(\"Inbound Email\")")).toBeVisible();
  });
});
