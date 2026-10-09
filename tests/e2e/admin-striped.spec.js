// @ts-check
const { test, expect } = require('@playwright/test');
const { AdminPhotoPage } = require('./support/AdminPhotoPage');
const { AdminTagsPage } = require('./support/AdminTagsPage');
const { seed, restore } = require('./support/seed');

/**
 * A striped group with an emoji on the two admin screens that draw groups.
 *
 * GroupStyleTest asserts the CSS and markup the server sends. Whether
 * selectize's chips and tags.js's dots actually take it is only visible
 * here: the chips are built by selectize at runtime, and the dots are
 * painted by tags.js from a JSON payload.
 */
test.describe('a striped group on the admin screens', () => {
  test.afterEach(async () => {
    restore();
  });

  test('the photo properties chip gets the tab, the border and the emoji', async ({ page }) => {
    const fixture = seed('all-assigned', 1, { stripedGroup: true });
    const tagId = fixture.striped_tag_id;
    expect(fixture.assigned).toContain(tagId);
    const { rgb: colour, text_rgb: text } = fixture.colors[tagId];

    const photo = new AdminPhotoPage(page);
    await photo.open(fixture.image_id);
    await expect(photo.chip(tagId)).toBeVisible();

    const chip = await photo.chipPaint(tagId);
    expect(chip.backgroundImage).toMatch(/^repeating-linear-gradient\(45deg, /);
    expect(chip.backgroundImage).toContain(colour);
    expect(chip.borderColor).toBe(colour);
    expect(chip.color).toBe(text);
    expect(chip.before).toBe(`"${fixture.striped_emoji}"`);
  });

  test('the tags screen paints the striped dot and swatch', async ({ page }) => {
    const fixture = seed('no-tags', 1, { stripedGroup: true });
    const colour = fixture.colors[fixture.striped_tag_id].rgb;

    const tags = new AdminTagsPage(page);
    await tags.open();
    await expect(tags.tagColorSample(fixture.striped_tag_id)).toHaveCount(1);
    const dot = await AdminTagsPage.paint(tags.tagColorSample(fixture.striped_tag_id));
    expect(dot.backgroundImage).toContain(colour);

    await tags.walkToColorPanel();
    const swatch = tags.colorOptionSample(fixture.striped_group_id);
    await expect(swatch).toBeVisible();
    expect((await AdminTagsPage.paint(swatch)).backgroundImage).toContain(colour);
    await expect(tags.colorOptionName(fixture.striped_group_id)).toContainText(fixture.striped_emoji);
  });
});
