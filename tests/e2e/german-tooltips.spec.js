// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * The tooltips on the two controls the browser builds, in the DOM.
 *
 * GermanScreenTest already asserts the wording, but it reads page source, where
 * these titles sit inside a script rather than on an element: the × control
 * does not exist in source at all, and the + badge is rebuilt by the same script
 * after a removal. A source assertion therefore stays green if the script stops
 * carrying its declaration onto the element, which is exactly the regression a
 * reader of the handbook would see.
 *
 * The wording itself is not restated here - that rule lives one layer down.
 * These specs compare the rendered attribute against the declaration in the
 * script's own text, so no translated string is typed into a spec.
 */
test.describe('tooltips on the controls the browser builds', () => {
  test.afterEach(async () => {
    restore();
  });

  test('the remove control carries the tooltip its declaration names', async ({ page }) => {
    const fixture = seed('some-assigned');
    // Anti-vacuity: with nothing assigned there is no × control to inspect.
    expect(fixture.assigned_colored_count).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await expect(picture.removeButtons).toHaveCount(fixture.assigned_colored_count);

    const declared = await picture.declaredTooltip('typetag-remove');
    expect(declared).toBeTruthy();

    await expect(picture.removeButton(fixture.assigned[0])).toHaveAttribute('title', String(declared));
  });

  test('a badge rebuilt after a removal carries the tooltip its declaration names', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.assigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await picture.removeButton(tagId).click();
    // Anti-vacuity: the badge under test is the one the script just created.
    await expect(picture.addBadge(tagId)).toHaveCount(1);

    const declared = await picture.declaredTooltip('typetag-add');
    expect(declared).toBeTruthy();

    await expect(picture.addBadge(tagId)).toHaveAttribute('title', String(declared));
  });
});
