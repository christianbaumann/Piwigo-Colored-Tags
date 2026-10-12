// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const { TagGroupsPage } = require('./support/TagGroupsPage');
const { seed, restore } = require('./support/seed');

const WHITE = 'rgb(255, 255, 255)';

/**
 * The read-only colour group page as a normal account sees it: reached from
 * the tags page, every group painted as its badges are, nothing to change.
 * Which tags are listed (album permissions) is witnessed by the integration
 * suite (TagGroupsPageTest) and not restated here.
 */
test.describe('the tag group page for a normal account', () => {
  test.use({ storageState: path.join(__dirname, '.state', 'auth-normal.json') });

  test.afterEach(async () => {
    restore();
  });

  test('opens from the tags page and paints the striped group', async ({ page }) => {
    const fixture = seed('no-tags', null, { stripedGroup: true, assignStriped: true });
    const tagId = fixture.striped_tag_id;
    const groupId = fixture.striped_group_id;
    const expected = fixture.colors[tagId];
    expect(expected.striped).toBe(true);

    const groups = new TagGroupsPage(page);
    await groups.gotoTagsPage();
    await groups.tagsPageLink.click();

    await expect(page).toHaveURL(/index\.php\?\/taggroups$/);
    await expect(groups.name(groupId)).toBeVisible();
    await expect(groups.emoji(groupId)).toHaveText(fixture.striped_emoji);
    await expect(groups.badge(groupId, tagId)).toBeVisible();

    const paint = await groups.badgePaint(groupId, tagId);
    expect(paint.backgroundColor).toBe(WHITE);
    expect(paint.backgroundImage).toMatch(/^repeating-linear-gradient\(45deg, /);
    expect(paint.backgroundImage).toContain(expected.rgb);
    expect(paint.borderColor).toBe(expected.rgb);
    expect(paint.color).toBe(expected.text_rgb);
  });

  test('offers no control to change anything', async ({ page }) => {
    const fixture = seed('no-tags', null, { stripedGroup: true, assignStriped: true });

    const groups = new TagGroupsPage(page);
    await groups.goto();

    // Anti-vacuity: the list rendered, so an absent control means something.
    await expect(groups.badge(fixture.striped_group_id, fixture.striped_tag_id)).toBeVisible();
    await expect(groups.controls).toHaveCount(0);
  });
});
