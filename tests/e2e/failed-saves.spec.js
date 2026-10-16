// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * A failed save through + or x reports its reason where a typed tag's does,
 * and the badge stays where it was, which is what the server holds
 * (task 10, design "+ and x report a failed save").
 *
 * The failures are the real server's: the request is sent with one field
 * changed, so the server answers with a reason of its own. A tag that does not
 * exist is a PwgError with HTTP 404 (jQuery's error callback); an image id of
 * 0 is refused by the parameter check with HTTP 200 and stat "fail" (the
 * success callback). The two reach different code, so each is its own case.
 */
const STATE = path.join(__dirname, '.state');
const ROLES = [
  { role: 'generic', storageState: path.join(STATE, 'auth-generic.json') },
  { role: 'normal', storageState: path.join(STATE, 'auth-normal.json') },
  { role: 'admin', storageState: path.join(STATE, 'auth-admin.json') },
  { role: 'webmaster', storageState: path.join(STATE, 'auth.json') },
];
const MISSING_TAG = '999999999';

for (const { role, storageState } of ROLES) {
  test.describe(`a failed + or x as ${role}`, () => {
    test.use({ storageState });

    test.afterEach(() => {
      restore();
    });

    test('[NEG] + and x show the reason and the badges stay as saved', async ({ page }) => {
      const fixture = seed('some-assigned');
      expect(fixture.unassigned_colored_count).toBeGreaterThan(0);
      expect(fixture.assigned_colored_count).toBeGreaterThan(0);

      const picture = new PicturePage(page);
      await picture.gotoFixture(fixture);
      const addId = await picture.addBadges.first().getAttribute('data-tag-id');
      const removeId = await picture.removeButtons.first().getAttribute('data-tag-id');
      expect(addId).not.toBeNull();
      expect(removeId).not.toBeNull();
      const before = await picture.assignedTagIds();
      await expect(picture.newTagError).toBeHidden();

      // HTTP 404 from the server
      await picture.corruptNextCall('typetags.image.addTag', 'tag_id', MISSING_TAG);
      const added = await picture.clickAddBadge(addId);
      expect(added.stat).toBe('fail');
      await expect(picture.newTagError).toBeVisible();
      await expect(picture.newTagError).toHaveText(added.message);
      await expect(picture.addBadge(addId)).toHaveCount(1);
      await expect(picture.assignedTag(addId)).toHaveCount(0);

      // HTTP 200 with stat "fail"
      await picture.corruptNextCall('typetags.image.removeTag', 'image_id', '0');
      const removed = await (await picture.clickRemoveButton(removeId)).json();
      expect(removed.stat).toBe('fail');
      await expect(picture.newTagError).toHaveText(removed.message);
      await expect(picture.assignedTag(removeId)).toHaveCount(1);
      await expect(picture.addBadge(removeId)).toHaveCount(0);
      expect(await picture.assignedTagIds()).toEqual(before);

      // the page showed what the server holds
      await picture.reload();
      expect(await picture.assignedTagIds()).toEqual(before);

      // and the next save goes through and clears the message
      await picture.clickAddBadge(addId);
      await expect(picture.assignedTag(addId)).toHaveCount(1);
      await expect(picture.newTagError).toBeHidden();
    });
  });
}

test.describe('a + or x without an answer', () => {
  test.afterEach(() => {
    restore();
  });

  test('[NEG] a lost connection says the tag was not saved', async ({ page }) => {
    const fixture = seed('some-assigned');
    expect(fixture.assigned_colored_count).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const removeId = await picture.removeButtons.first().getAttribute('data-tag-id');
    const notSaved = await picture.failureText('not-saved');
    expect(notSaved).toBeTruthy();

    await picture.abortNextCall('typetags.image.removeTag');
    await picture.removeButton(removeId).click();

    await expect(picture.newTagError).toHaveText(/** @type {string} */ (notSaved));
    await expect(picture.assignedTag(removeId)).toHaveCount(1);
  });

  test('[NEG] an expired session says so, for + and for typing', async ({ page }) => {
    const fixture = seed('some-assigned');
    expect(fixture.unassigned_colored_count).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const addId = await picture.addBadges.first().getAttribute('data-tag-id');
    const expired = await picture.failureText('session-expired');
    const notSaved = await picture.failureText('not-saved');
    expect(expired).toBeTruthy();
    expect(expired).not.toBe(notSaved);

    await picture.endSession();
    const added = await picture.clickAddBadge(addId);
    expect(added.err).toBe(401);
    await expect(picture.newTagError).toHaveText(/** @type {string} */ (expired));
    await expect(picture.addBadge(addId)).toHaveCount(1);

    await picture.addNewTag('_test_e2e_expired');
    await expect(picture.newTagError).toHaveText(/** @type {string} */ (expired));
  });
});
