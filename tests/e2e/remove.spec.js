// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * The remove flow. The × button does not exist in page source at all — it is
 * appended inside the badge span by the injected script — so every assertion
 * here needs a real browser.
 *
 * Plan B checklist boxes 515, 545-549.
 */
test.describe('removing an assigned coloured tag', () => {
  test.afterEach(async () => {
    restore();
  });

  test('assigned coloured tags show a remove button', async ({ page }) => {
    const fixture = seed('all-assigned');
    // Anti-vacuity: zero assigned tags would make "no button is missing" true.
    expect(fixture.assigned_colored_count).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await expect(picture.assignedTags).toHaveCount(fixture.assigned_colored_count);
    await expect(picture.removeButtons).toHaveCount(fixture.assigned_colored_count);
    await expect(picture.removeButton(fixture.assigned[0])).toBeVisible();
  });

  test('clicking it removes the tag from the Tags row', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.assigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await expect(picture.assignedTag(tagId)).toHaveCount(1);

    await picture.removeButton(tagId).click();

    await expect(picture.assignedTag(tagId)).toHaveCount(0);
  });

  test('the tag reappears in the unassigned list at reduced opacity', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.assigned[0];
    const before = fixture.unassigned_colored_count;

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const assignedName = (await picture.assignedNames())[0];
    expect(assignedName).toBeTruthy();

    await picture.removeButton(tagId).click();

    await expect(picture.addBadge(tagId)).toHaveCount(1);
    await expect(picture.addBadges).toHaveCount(before + 1);
    expect(await PicturePage.computedOpacity(picture.addBadge(tagId))).toBe('0.6');
    expect(await picture.unassignedLabels()).toContain(`+ ${assignedName}`);
  });

  test('the Tags row hides when the last tag is removed', async ({ page }) => {
    const fixture = seed('some-assigned');
    expect(fixture.assigned_colored_count).toBe(1);
    const tagId = fixture.assigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    expect(await picture.tagsRowIsShown()).toBe(true);

    await picture.removeButton(tagId).click();

    await expect(picture.tagsRow).toBeHidden();
  });

  test('the unassigned section is recreated when it had been hidden', async ({ page }) => {
    const fixture = seed('all-assigned');
    expect(fixture.unassigned_colored_count).toBe(0);
    const tagId = fixture.assigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    // State B: with nothing unassigned the {if} is false, so the container is
    // not merely hidden — it was never rendered. The JS has to build it.
    await expect(picture.unassignedBox).toHaveCount(0);

    await picture.removeButton(tagId).click();

    await expect(picture.unassignedBox).toHaveCount(1);
    await expect(picture.unassignedBox).toBeVisible();
    await expect(picture.addBadge(tagId)).toHaveCount(1);
  });

  test('the removal survives a page reload', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.assigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await picture.removeButton(tagId).click();
    await expect(picture.assignedTag(tagId)).toHaveCount(0);

    await picture.reload();

    await expect(picture.assignedTag(tagId)).toHaveCount(0);
    await expect(picture.addBadge(tagId)).toHaveCount(1);
  });

  test('add then remove returns the page to its starting state', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.unassigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const namesBefore = await picture.assignedNames();
    const unassignedBefore = await picture.unassignedLabels();
    expect(namesBefore.length).toBeGreaterThan(0);
    expect(unassignedBefore.length).toBeGreaterThan(0);

    await picture.addBadge(tagId).click();
    await expect(picture.assignedTag(tagId)).toHaveCount(1);

    await picture.removeButton(tagId).click();
    await expect(picture.assignedTag(tagId)).toHaveCount(0);

    expect(await picture.assignedNames()).toEqual(namesBefore);
    expect((await picture.unassignedLabels()).sort()).toEqual([...unassignedBefore].sort());
  });
});
