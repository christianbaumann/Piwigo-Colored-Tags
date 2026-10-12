// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * The role matrix rows the browser has to witness: the injected script builds
 * the x on a badge and fills the write warning only after the ws answer, so
 * page source cannot show them. Which level each role gets is the owner's
 * matrix of 2026-10-12 (Piwigo change 2026-10-12-role-test-coverage):
 * typetags.assign-remove is edit and typetags.write-warning is see for every
 * logged-in account, none for a guest. The server half of every row is
 * RoleMatrixTest's.
 */
const STATE = path.join(__dirname, '.state');
const ROLES = [
  { role: 'generic', storageState: path.join(STATE, 'auth-generic.json') },
  { role: 'normal', storageState: path.join(STATE, 'auth-normal.json') },
  { role: 'admin', storageState: path.join(STATE, 'auth-admin.json') },
  { role: 'webmaster', storageState: path.join(STATE, 'auth.json') },
];

for (const { role, storageState } of ROLES) {
  test.describe(`the picture page as ${role}`, () => {
    test.use({ storageState });

    test.afterEach(async () => {
      restore();
    });

    test(`${role === 'generic' ? '[ECP]' : '[HAPPY]'} typetags.assign-remove: the + badge adds a tag and its x takes it off`, async ({ page }) => {
      const fixture = seed('some-assigned');
      // Anti-vacuity: with nothing unassigned there is no badge to click.
      expect(fixture.unassigned_colored_count).toBeGreaterThan(0);

      const picture = new PicturePage(page);
      await picture.gotoFixture(fixture);
      const before = await picture.assignedTags.count();
      const tagId = await picture.addBadges.first().getAttribute('data-tag-id');
      expect(tagId).not.toBeNull();

      await picture.addBadge(tagId).click();
      await expect(picture.assignedTag(tagId)).toHaveCount(1);
      await expect(picture.assignedTags).toHaveCount(before + 1);

      await picture.removeButton(tagId).click();
      await expect(picture.assignedTag(tagId)).toHaveCount(0);
      await expect(picture.assignedTags).toHaveCount(before);
      await expect(picture.addBadge(tagId)).toHaveCount(1);
    });

    test(`${role === 'generic' ? '[ECP]' : '[HAPPY]'} typetags.write-warning: a tag not written into the file shows the reason`, async ({ page }) => {
      const fixture = seed('some-assigned', null, { readOnly: true });
      expect(fixture.unassigned_colored_count).toBeGreaterThan(0);

      const picture = new PicturePage(page);
      await picture.gotoFixture(fixture);
      await expect(picture.tagWarning).toBeHidden();
      const tagId = await picture.addBadges.first().getAttribute('data-tag-id');

      const answer = await picture.clickAddBadge(tagId);

      // The oracle is the server's answer; the page must show exactly its reason.
      expect(answer.stat).toBe('ok');
      expect(answer.result.tags_written).toBe(false);
      expect(answer.result.tags_message_l10n).toBeTruthy();
      await expect(picture.tagWarning).toBeVisible();
      await expect(picture.tagWarning).toHaveText(answer.result.tags_message_l10n);
    });
  });
}

test.describe('the picture page as a guest', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test.afterEach(async () => {
    restore();
  });

  test('[NEG] typetags.assign-remove and write-warning: the login page instead', async ({ page }) => {
    const fixture = seed('some-assigned');
    expect(fixture.unassigned_colored_count).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await expect(page).toHaveURL(/identification\.php/);
    await expect(picture.loginField).toHaveCount(1);
    await expect(picture.addBadges).toHaveCount(0);
    await expect(picture.removeButtons).toHaveCount(0);
    await expect(picture.tagWarning).toHaveCount(0);
  });
});
