// @ts-check
const { test, expect } = require('@playwright/test');
const { AdminTagsPage } = require('./support/AdminTagsPage');
const { deleteTypetag } = require('./support/seed');

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

/**
 * Creating a colour, end to end, through the path the handbook documents
 * (04-schlagworte.html:153-172): selection mode on, a tag selected, the colour
 * button, then the "Add a new color" form.
 *
 * This is the browser half of the typetags.type.add gate. The integration suite
 * proves the method refuses anonymous and non-admin callers and accepts the
 * webmaster; only a browser can prove the admin screen's own request still gets
 * through with the session it carries. Gating the method too tightly - by
 * pwg_token as well, say - would leave every integration case green and break
 * exactly this path, because tags.js:228-247 sends no token.
 */
test.describe('creating a colour on the tag administration screen', () => {
  const FIXTURE_NAME = '_test_e2e_colour_creation';
  const FIXTURE_HEX = '#123456';

  // Unconditional, not conditional on the test having got that far: a spec
  // killed mid-run would otherwise leave the colour in the palette of every
  // later run.
  test.afterEach(() => {
    deleteTypetag(FIXTURE_NAME);
  });

  test('the webmaster creates a colour and it joins the palette', async ({ page }) => {
    const tags = new AdminTagsPage(page);
    await tags.open();

    // Anti-vacuity: a palette that rendered no swatch at all would make the
    // "one more than before" assertion below pass for the wrong reason.
    await tags.walkToColorPanel();
    const before = await tags.colorOptions.count();
    expect(before).toBeGreaterThan(0);

    const created = page.waitForResponse((r) => r.url().includes('ws.php') && r.status() === 200);
    await tags.createColor(FIXTURE_NAME, FIXTURE_HEX);
    const payload = await (await created).json();

    // The method answered this caller, which is the fact the gate could break.
    expect(payload.stat).toBe('ok');
    expect(payload.result.name).toBe(FIXTURE_NAME);

    await expect(tags.createSuccessMessage).toBeVisible();
    await expect(tags.createError).toBeHidden();

    // The new swatch is on screen and carries the colour that was asked for.
    await expect(tags.colorOption(payload.result.id)).toBeVisible();
    await expect(tags.colorOptions).toHaveCount(before + 1);
    expect(payload.result.color).toBe(FIXTURE_HEX);

    // And it survives a reload, so the row really reached the database rather
    // than only the DOM addColorOption() built.
    await tags.open();
    await tags.walkToColorPanel();
    await expect(tags.colorOption(payload.result.id)).toBeVisible();
  });

  test('the palette is back to its original size once the colour is removed', async ({ page }) => {
    const tags = new AdminTagsPage(page);
    await tags.open();
    await tags.walkToColorPanel();
    const before = await tags.colorOptions.count();
    expect(before).toBeGreaterThan(0);

    await tags.createColor(FIXTURE_NAME, FIXTURE_HEX);
    await expect(tags.createSuccessMessage).toBeVisible();

    deleteTypetag(FIXTURE_NAME);

    await tags.open();
    await tags.walkToColorPanel();
    await expect(tags.colorOptions).toHaveCount(before);
  });
});
