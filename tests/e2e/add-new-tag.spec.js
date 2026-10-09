// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore, trackTag } = require('./support/seed');

/**
 * The picture page's field for a tag that is not offered as a + badge
 * (plan 2026-10-09, Phase 5, Q15): any logged-in account types a name, the
 * tag is created or found and appears in the Tags row without a reload.
 *
 * The name is typed by any account, so the script inserts it as text, never
 * as markup. The server strips tags from it as core does from its own tag
 * fields; the browser must not turn what is left into elements either.
 */
const RUN = Date.now().toString(36);

test.describe('a normal account types a new tag', () => {
  test.use({ storageState: path.join(__dirname, '.state', 'auth-normal.json') });

  test.afterEach(async () => {
    restore();
  });

  test('sees the field and the typed tag lands in the Tags row', async ({ page }) => {
    const fixture = seed('no-tags');
    const name = `Kirmes ${RUN}`;
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await expect(picture.newTagField).toBeVisible();
    const before = await picture.assignedTags.count();

    await picture.addNewTag(name);
    const tag = trackTag(name);

    expect(tag.tag_id).not.toBeNull();
    await expect(picture.assignedTag(tag.tag_id)).toHaveCount(1);
    await expect(picture.assignedTags).toHaveCount(before + 1);
    await expect(picture.newTagField).toHaveValue('');
    await expect(picture.newTagError).toBeHidden();
  });

  test('a typed name with markup shows as text', async ({ page }) => {
    const fixture = seed('no-tags');
    const typed = `<b>Fett</b> <i>${RUN}</i>`;
    const stored = `Fett ${RUN}`;
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await picture.addNewTag(typed);
    const tag = trackTag(stored);

    expect(tag.tag_id).not.toBeNull();
    await expect(picture.assignedTag(tag.tag_id)).toHaveCount(1);
    expect(await picture.assignedNames()).toContain(stored);
    await expect(picture.markupInTagsRow).toHaveCount(0);

    await picture.reload();
    await expect(picture.assignedTag(tag.tag_id)).toHaveCount(1);
    await expect(picture.markupInTagsRow).toHaveCount(0);
  });

  test('a refused name keeps the text and shows the message', async ({ page }) => {
    const fixture = seed('no-tags');
    // A character outside the Basic Multilingual Plane, which the tags table cannot hold.
    const name = `Kirmes \u{1F389} ${RUN}`;
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const before = await picture.assignedTags.count();

    await picture.addNewTag(name);

    await expect(picture.newTagError).toBeVisible();
    await expect(picture.newTagError).not.toHaveText('');
    await expect(picture.newTagField).toHaveValue(name);
    // AddNewTagTest proves no tag row is left; the table cannot even be asked for this name.
    await expect(picture.assignedTags).toHaveCount(before);
  });

  test('a failed request keeps the text and shows a message', async ({ page }) => {
    const fixture = seed('no-tags');
    const name = `Kirmes ${RUN}`;
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await page.route('**/ws.php*', (route) => route.abort());

    await picture.newTagField.fill(name);
    await picture.newTagButton.click();

    await expect(picture.newTagError).toBeVisible();
    await expect(picture.newTagError).not.toHaveText('');
    await expect(picture.newTagField).toHaveValue(name);
    await expect(picture.newTagButton).toBeEnabled();
  });
});

test.describe('a guest', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test.afterEach(async () => {
    restore();
  });

  test('gets no field', async ({ page }) => {
    const fixture = seed('no-tags');
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    // Anti-vacuity: the guest really is on the photo's page.
    await expect(page).toHaveURL(new RegExp(`/picture\\.php\\?/${fixture.image_id}/`));
    await expect(picture.newTagForm).toHaveCount(0);
  });
});
