// @ts-check
const { test: setup, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '.state', 'auth.json');
const NORMAL_AUTH_FILE = path.join(__dirname, '.state', 'auth-normal.json');

/**
 * Logs in once and saves the session, because the assignment UI is only
 * rendered for authenticated users (typetags_picture_tags() returns early for
 * a guest). Credentials come from the environment, never from this file — the
 * same rule the PHPUnit Config class enforces. The account is one the suite
 * creates for itself; no human's login is ever used.
 */
setup('authenticate', async ({ page }) => {
  const username = process.env.TYPETAGS_TEST_WEBMASTER_USERNAME;
  const password = process.env.TYPETAGS_TEST_WEBMASTER_PASSWORD;

  if (!username || !password) {
    throw new Error(
      'Missing TYPETAGS_TEST_WEBMASTER_USERNAME / TYPETAGS_TEST_WEBMASTER_PASSWORD. ' +
        'Run `ddev exec php plugins/typetags/tests/Support/create-test-users.php` to create the ' +
        'test accounts, then source local/config/typetags-test.env before running the E2E suite.'
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

/**
 * Logs in once as the suite's own non-administrator account.
 *
 * The whole suite otherwise runs as a webmaster, which cannot witness the
 * permission this plugin deliberately grants: decision 0005 opens colour
 * assignment on the picture page to any logged-in non-guest rather than to
 * administrators, and docs/handbuch/04-schlagworte.html tells readers so. Only
 * a normal account can prove it.
 */
setup('authenticate as a normal account', async ({ page }) => {
  const username = process.env.TYPETAGS_TEST_NORMAL_USERNAME;
  const password = process.env.TYPETAGS_TEST_NORMAL_PASSWORD;

  if (!username || !password) {
    throw new Error(
      'Missing TYPETAGS_TEST_NORMAL_USERNAME / TYPETAGS_TEST_NORMAL_PASSWORD. ' +
        'Run `ddev exec php plugins/typetags/tests/Support/create-test-users.php` to create the ' +
        'test accounts, then source local/config/typetags-test.env before running the E2E suite.'
    );
  }

  await page.goto('/identification.php');
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await page.click('input[name="login"]');

  await expect(page.locator('input[name="username"]')).toHaveCount(0);

  fs.mkdirSync(path.dirname(NORMAL_AUTH_FILE), { recursive: true });
  await page.context().storageState({ path: NORMAL_AUTH_FILE });
});
