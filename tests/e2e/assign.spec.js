// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * The add flow, at the only layer that can witness it: the badge markup these
 * assertions read is assembled at runtime by the injected footer script, so it
 * is unreachable from a page-source assertion.
 *
 * Plan B checklist boxes 514, 537-542 and the second half of 556.
 */
test.describe('assigning a coloured tag', () => {
  test.afterEach(async () => {
    restore();
  });

  test('unassigned badges render at reduced opacity with a plus prefix', async ({ page }) => {
    const fixture = seed('some-assigned');
    // Anti-vacuity: a fixture with nothing unassigned would pass every
    // assertion below over an empty list.
    expect(fixture.unassigned_colored_count).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await expect(picture.addBadges).toHaveCount(fixture.unassigned_colored_count);

    const labels = await picture.unassignedLabels();
    for (const label of labels) {
      expect(label).toMatch(/^\+ \S/);
    }

    expect(await PicturePage.computedOpacity(picture.addBadge(fixture.unassigned[0]))).toBe('0.6');
  });

  test('clicking an unassigned badge moves it into the Tags row at full opacity', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.unassigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    const tagName = await picture.unassignedTagName(tagId);
    expect(tagName).toBeTruthy();
    await expect(picture.assignedTag(tagId)).toHaveCount(0);

    await picture.addBadge(tagId).click();

    await expect(picture.assignedTag(tagId)).toHaveCount(1);
    expect(await picture.assignedNames()).toContain(tagName);
    // Full opacity, and no "+" prefix carried over from the unassigned badge.
    expect(await PicturePage.computedOpacity(picture.assignedTag(tagId))).toBe('1');
    expect(await picture.assignedNames()).not.toContain(`+ ${tagName}`);
  });

  test('a remove button appears on the newly assigned tag', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.unassigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await expect(picture.removeButton(tagId)).toHaveCount(0);

    await picture.addBadge(tagId).click();

    await expect(picture.removeButton(tagId)).toHaveCount(1);
    await expect(picture.removeButton(tagId)).toBeVisible();
  });

  test('the badge disappears from the unassigned list', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.unassigned[0];
    const before = fixture.unassigned_colored_count;
    expect(before).toBeGreaterThan(1); // so the list shrinks rather than emptying

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await picture.addBadge(tagId).click();

    await expect(picture.addBadge(tagId)).toHaveCount(0);
    await expect(picture.addBadges).toHaveCount(before - 1);
    expect(await picture.unassignedBoxIsShown()).toBe(true);
  });

  test('the unassigned section hides when the last tag is assigned', async ({ page }) => {
    const fixture = seed('all-but-one-assigned');
    expect(fixture.unassigned_colored_count).toBe(1);
    const tagId = fixture.unassigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    expect(await picture.unassignedBoxIsShown()).toBe(true);

    await picture.addBadge(tagId).click();

    await expect(picture.assignedTag(tagId)).toHaveCount(1);
    await expect(picture.unassignedBox).toBeHidden();
  });

  test('the Tags row is created when the image had no tags', async ({ page }) => {
    const fixture = seed('no-tags');
    expect(fixture.assigned_colored_count).toBe(0);
    const tagId = fixture.unassigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    // State C's precondition: the theme renders no #Tags element at all for an
    // untagged image, so the JS has to build the row rather than fill it.
    await expect(picture.tagsRow).toHaveCount(0);

    await picture.addBadge(tagId).click();

    await expect(picture.tagsRow).toHaveCount(1);
    await expect(picture.tagsRow).toBeVisible();
    await expect(picture.assignedTag(tagId)).toHaveCount(1);
    expect(await picture.tagsRowPrecedesCategories()).toBe(true);
  });

  test('the assignment survives a page reload', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.unassigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const tagName = await picture.unassignedTagName(tagId);

    await picture.addBadge(tagId).click();
    await expect(picture.assignedTag(tagId)).toHaveCount(1);

    await picture.reload();

    // Server-rendered this time, so this witnesses the write, not the DOM edit.
    await expect(picture.assignedTag(tagId)).toHaveCount(1);
    await expect(picture.addBadge(tagId)).toHaveCount(0);
    expect(await picture.assignedNames()).toContain(tagName);
  });
});
