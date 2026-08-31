// @ts-check
const { test, expect } = require('@playwright/test');
const { AdminTagsPage } = require('./support/AdminTagsPage');

/**
 * The colour controls on the tag administration screen, in the DOM.
 *
 * GermanScreenTest asserts the wording of "Farbe", "Farbe entfernen" and
 * "Erstellen" in the page source. It cannot assert that a user ever sees them:
 * all three are hidden on arrival and reachable only two interactions deep
 * (selection mode on, a tag selected, then the colour button). A source
 * assertion stays green if the panel stops opening, and the handbook page that
 * tells a reader to click through to it would be wrong with nothing failing.
 *
 * The wording itself is not restated here - that rule lives one layer down.
 * Each spec compares the text the user sees against the text the server put in
 * the element, so no translated string is typed into a spec. That comparison is
 * not a tautology: on the same screen, core's rename popin overwrites its own
 * submit label before showing it, which is the defect this shape detects (see
 * core-admin-screens.spec.js in the provenance suite).
 */
test.describe('the colour controls on the tag administration screen', () => {
  test('the colour panel opens two interactions deep and keeps the text the server sent', async ({ page }) => {
    const tags = new AdminTagsPage(page);
    await tags.open();

    // Anti-vacuity: with no tag on the screen there is nothing to select, and
    // the panel below could never be reached for the right reason.
    expect(await tags.tagNames.count()).toBeGreaterThan(0);

    const createText = await AdminTagsPage.serverText(tags.createButton);
    const removeColorText = await AdminTagsPage.serverText(tags.removeColorLabel);
    expect(createText).not.toBe('');
    expect(removeColorText).not.toBe('');

    await expect(tags.colorPanel).toBeHidden();

    await tags.enterSelectionMode();
    await tags.selectFirstTag();
    await expect(tags.colorButton).toBeVisible();

    await tags.openColorPanel();

    await expect(tags.createButton).toBeVisible();
    await expect(tags.removeColorLabel).toBeVisible();
    await expect(tags.createButton).toHaveText(createText);
    await expect(tags.removeColorLabel).toHaveText(removeColorText);
  });

  test('the colour button stays out of reach until a tag is selected', async ({ page }) => {
    const tags = new AdminTagsPage(page);
    await tags.open();

    await expect(tags.colorButton).toBeHidden();

    await tags.enterSelectionMode();

    // Selection mode alone is not enough: core keeps the action row behind its
    // "nothing selected" notice, and the handbook has to say so.
    await expect(tags.nothingSelected).toBeVisible();
    await expect(tags.colorButton).toBeHidden();
  });
});
