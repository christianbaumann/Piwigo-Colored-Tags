// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * The permission this plugin deliberately grants, witnessed by the only account
 * that can prove it.
 *
 * Decision 0005 opens colour assignment on the picture page to any logged-in
 * non-guest rather than to administrators: main.inc.php:191-199 and :242-250
 * gate on is_a_guest() plus the token, and declare no admin_only.
 * docs/handbuch/04-schlagworte.html builds a whole section on that - it tells
 * readers without administrator rights to add and remove coloured tags here.
 *
 * Every other spec in this suite runs as typetags_webmaster, which passes an
 * admin gate as readily as a non-admin one and so cannot tell the two apart. If
 * a future change made these methods admin_only, nothing here would have gone
 * red and the handbook would be quietly wrong for most of its readers.
 *
 * The guest half is not restated: edge-cases and the plugin's own integration
 * suite already cover a guest getting no UI.
 */
test.describe('a normal account on the picture page', () => {
  test.use({ storageState: path.join(__dirname, '.state', 'auth-normal.json') });

  test.afterEach(async () => {
    restore();
  });

  test('adds a coloured tag and takes it off again', async ({ page }) => {
    const fixture = seed('some-assigned');
    // Anti-vacuity: with nothing unassigned there is no badge to click, and
    // every assertion below would pass over an empty list.
    expect(fixture.unassigned_colored_count).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    const before = await picture.assignedTags.count();
    await expect(picture.addBadges).toHaveCount(fixture.unassigned_colored_count);

    const tagId = await picture.addBadges.first().getAttribute('data-tag-id');
    expect(tagId).not.toBeNull();

    await picture.addBadge(tagId).click();

    // Assigned, and the badge left the unassigned list: the same outcome the
    // webmaster specs assert, now reached without administrator rights.
    await expect(picture.assignedTag(tagId)).toHaveCount(1);
    await expect(picture.assignedTags).toHaveCount(before + 1);
    await expect(picture.addBadges).toHaveCount(fixture.unassigned_colored_count - 1);

    await picture.removeButton(tagId).click();

    await expect(picture.assignedTag(tagId)).toHaveCount(0);
    await expect(picture.assignedTags).toHaveCount(before);
    await expect(picture.addBadges).toHaveCount(fixture.unassigned_colored_count);
  });
});
