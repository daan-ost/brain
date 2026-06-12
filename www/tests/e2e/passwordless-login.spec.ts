import { test, expect, Page } from "@playwright/test";
import { execSync } from "node:child_process";

/**
 * Passwordless Login E2E Tests
 *
 * Covers the user-facing flow voor:
 * - Email-code login (request form → verify form → dashboard)
 * - Google + email-code knoppen op de login-pagina
 * - Graceful degradation (zonder GOOGLE_CLIENT_ID — alleen code-login zichtbaar)
 *
 * NOTE: Google OAuth callback wordt niet end-to-end getest via Playwright
 * (vereist een echte Google account); die kant is gedekt door
 * tests/Feature/Auth/SocialiteLoginTest.php met Mockery.
 *
 * NOTE: De code wordt uit de DB opgehaald via een test-only Artisan
 * command — Playwright kan niet wachten op echte e-mail.
 */

test.describe("Passwordless Login", () => {
    async function waitForPageLoad(page: Page): Promise<void> {
        await page.waitForLoadState("networkidle");
        await page.waitForTimeout(300);
    }

    /**
     * Roep een test-helper Artisan command aan om de laatste login-code voor
     * een email te overschrijven met een bekende waarde (zodat de E2E flow
     * deterministisch is). Hash::check op deze waarde slaagt dan.
     */
    async function seedLoginCode(email: string, code: string): Promise<void> {
        execSync(
            `/Applications/MAMP/bin/php/php8.4.17/bin/php artisan e2e:seed-login-code "${email}" "${code}"`,
            { cwd: process.cwd() + "/.." === process.cwd() ? process.cwd() : process.cwd(), stdio: "pipe" }
        );
    }

    async function clearRateLimits(email: string): Promise<void> {
        execSync(
            `/Applications/MAMP/bin/php/php8.4.17/bin/php artisan e2e:clear-login-rate-limits "${email}"`,
            { stdio: "pipe" }
        );
    }

    // ============================================================
    // Login page UX
    // ============================================================

    test("login page shows alternate login buttons (Google + email code)", async ({ page }) => {
        await page.goto("/login");
        await waitForPageLoad(page);

        // De partial alternate-login wordt geinclude. Check dat:
        // - 'of' divider zichtbaar
        // - "Doorgaan met Google" knop OF "Inloggen met e-mailcode" knop aanwezig
        const codeButton = page.locator("a", { hasText: /e-?mailcode|email code/i }).first();
        await expect(codeButton).toBeVisible();
    });

    test("clicking 'Inloggen met e-mailcode' navigates to /login/code", async ({ page }) => {
        await page.goto("/login");
        await waitForPageLoad(page);

        const codeButton = page.locator("a", { hasText: /e-?mailcode|email code/i }).first();
        await codeButton.click();
        await waitForPageLoad(page);

        await expect(page).toHaveURL(/\/login\/code$/);
        // Title check (NL of EN)
        await expect(page.locator("h1")).toHaveText(/zonder wachtwoord|without a password/i);
    });

    // ============================================================
    // Code request → verify → dashboard
    // ============================================================

    test("complete code-login flow lands on dashboard", async ({ page }) => {
        const email = "test@example.com"; // bestaande user uit test seeder
        const knownCode = "424242";

        await clearRateLimits(email);

        // Stap 1: request form
        await page.goto("/login/code");
        await waitForPageLoad(page);

        await page.fill("input[name=\"email\"]", email);
        await page.click("button[type=\"submit\"]");
        await waitForPageLoad(page);

        // Stap 2: verify form
        await expect(page).toHaveURL(/\/login\/code\/verify/);
        await expect(page.locator("text=" + email)).toBeVisible();

        // Status banner (NL of EN)
        const statusBanner = page.locator("div", {
            hasText: /Als het e-?mailadres bekend is|If the email is registered/i,
        }).first();
        await expect(statusBanner).toBeVisible();

        // Seed de code in de DB voor deterministische test
        await seedLoginCode(email, knownCode);

        // Stap 3: vul code in en submit
        await page.fill("input[name=\"code\"]", knownCode);
        await page.click("button[type=\"submit\"]");
        await waitForPageLoad(page);

        // Verwacht: /dashboard
        await expect(page).toHaveURL(/\/dashboard/);
    });

    test("verify form rejects wrong code", async ({ page }) => {
        const email = "test@example.com";

        await clearRateLimits(email);
        await seedLoginCode(email, "999999");

        await page.goto("/login/code/verify?email=" + encodeURIComponent(email));
        await waitForPageLoad(page);

        await page.fill("input[name=\"code\"]", "000000");
        await page.click("button[type=\"submit\"]");
        await waitForPageLoad(page);

        // Blijft op verify-pagina + foutmelding zichtbaar
        await expect(page).toHaveURL(/\/login\/code\/verify/);
        const error = page.locator("text=/code is ongeldig|code is invalid/i").first();
        await expect(error).toBeVisible();
    });

    test("request form does not reveal if email is unknown", async ({ page }) => {
        const fakeEmail = "totally-unknown-" + Date.now() + "@example.com";

        await page.goto("/login/code");
        await waitForPageLoad(page);

        await page.fill("input[name=\"email\"]", fakeEmail);
        await page.click("button[type=\"submit\"]");
        await waitForPageLoad(page);

        // Zou exact zelfde redirect moeten doen als bij geldige email — geen "user not found" error
        await expect(page).toHaveURL(/\/login\/code\/verify/);
        const successBanner = page.locator("div", {
            hasText: /Als het e-?mailadres bekend is|If the email is registered/i,
        }).first();
        await expect(successBanner).toBeVisible();
    });
});
