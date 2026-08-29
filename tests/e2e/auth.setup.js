// @ts-check
const { test: setup, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '.state', 'auth.json');

/**
 * Logs in once and saves the session, because the assignment UI is only
 * rendered for authenticated users (typetags_picture_tags() returns early for
 * a guest). Credentials come from the environment, never from this file — the
 * same rule the PHPUnit Config class enforces.
 */
setup('authenticate', async ({ page }) => {
  const username = process.env.TYPETAGS_TEST_USERNAME;
  const password = process.env.TYPETAGS_TEST_PASSWORD;

  if (!username || !password) {
    throw new Error(
      'Missing TYPETAGS_TEST_USERNAME / TYPETAGS_TEST_PASSWORD. ' +
        "Set them to a test user's login on this DDEV install before running the E2E suite."
    );
  }

  await page.goto('/identification.php');
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await page.click('input[name="login"]');

  // The login form re-renders itself on failure, so reaching a page without it
  // is the signal that authentication actually took — not merely that a
  // navigation happened.
  await expect(page.locator('input[name="username"]')).toHaveCount(0);

  fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true });
  await page.context().storageState({ path: AUTH_FILE });
});
