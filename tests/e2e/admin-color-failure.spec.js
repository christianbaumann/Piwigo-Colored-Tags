// @ts-check
const { test, expect } = require('@playwright/test');
const { AdminTagsPage } = require('./support/AdminTagsPage');
const { seed, restore } = require('./support/seed');

/**
 * "Schlagwort-Farben festlegen" after a failed save (task 10): the error
 * shows, the button stops spinning, an earlier "Farbe gespeichert" goes away,
 * and the next save goes through without a reload. One failure used to leave
 * the button spinning and block every later save.
 *
 * Both failures are the real server's: a group that is no number is refused by
 * the parameter check (HTTP 200, stat "fail"), a wrong token by the method
 * (HTTP 403, jQuery's error callback).
 */
test.describe('a failed colour save on the tag administration screen', () => {
  test.afterEach(() => {
    restore();
  });

  test('[NEG] shows the error, stops loading, and a second save goes through', async ({ page }) => {
    const fixture = seed('no-tags', null, { stripedGroup: true });
    const tagId = fixture.striped_tag_id;

    const tags = new AdminTagsPage(page);
    await tags.open();
    await tags.search(fixture.striped_tag_name);
    await tags.enterSelectionMode();
    await tags.selectTag(tagId);
    await tags.openColorPanel();
    await tags.chooseColor('n');

    // a save that works first, so there is a "Farbe gespeichert" to hide
    expect((await tags.validateColorAnswer()).stat).toBe('ok');
    await expect(tags.saveMessage).toBeVisible();
    await expect(tags.validateButton).not.toHaveClass(/loading/);

    // HTTP 200 with stat "fail"
    await tags.chooseColor(fixture.striped_group_id);
    await tags.corruptNextCall('typetags.tags.setType', 'typetag_id', 'x');
    const refused = await tags.validateColorAnswer();
    expect(refused.stat).toBe('fail');
    await expect(tags.saveError).toBeVisible();
    await expect(tags.saveError).toHaveText(refused.message);
    await expect(tags.saveMessage).toBeHidden();
    await expect(tags.validateButton).not.toHaveClass(/loading/);

    // HTTP 403
    await tags.corruptNextCall('typetags.tags.setType', 'pwg_token', '0');
    const forbidden = await tags.validateColorAnswer();
    expect(forbidden.err).toBe(403);
    await expect(tags.saveError).toHaveText(forbidden.message);
    await expect(tags.validateButton).not.toHaveClass(/loading/);

    // no reload: the same panel saves again
    expect((await tags.validateColorAnswer()).stat).toBe('ok');
    await expect(tags.saveMessage).toBeVisible();
    await expect(tags.saveError).toBeHidden();

    await tags.open();
    await tags.search(fixture.striped_tag_name);
    await expect(tags.tagColorSample(tagId)).toHaveCount(1);
  });
});
