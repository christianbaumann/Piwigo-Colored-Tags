// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore, trackTag } = require('./support/seed');

/**
 * Rückgängig on the Schlagwörter row (task 13). What the server undoes and refuses is photoinfo's
 * UndoTest; this covers the browser half: the button after a save, the badges going back where they
 * were without a reload, an undo started in the change list, the red refusal, and no button when the
 * answer carries no history entry (photoinfo not active).
 */
const SAVED_WITH_UNDO = '✓ Gespeichert · Rückgängig';
const SAVED = '✓ Gespeichert';
const UNDONE = '↶ Rückgängig gemacht';
const RUN = Date.now().toString(36);

test.describe('Rückgängig on the Schlagwörter row', () => {
  test.afterEach(() => {
    restore();
  });

  test('[HAPPY] an added tag is undone in place: badge back below the row, entry in the list', async ({ page }) => {
    const fixture = seed('some-assigned');
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await page.evaluate(() => { window.__notReloaded = true; });
    const tagId = await picture.addBadges.first().getAttribute('data-tag-id');
    await picture.clickAddBadge(Number(tagId));
    await expect(picture.assignedTag(Number(tagId))).toHaveCount(1);
    await expect(picture.rowStatus).toHaveText(SAVED_WITH_UNDO);

    await picture.rowUndo.click();

    await expect(picture.rowStatus).toHaveText(UNDONE);
    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--undone/);
    await expect(picture.assignedTag(Number(tagId))).toHaveCount(0);
    await expect(picture.addBadge(Number(tagId))).toHaveCount(1);
    expect(await page.evaluate(() => window.__notReloaded)).toBe(true);

    await picture.historySummary.click();
    await expect(picture.historyEntries).toHaveCount(2);
    await expect(page.locator('#photoinfo-history-list li.photoinfo-history-undone')).toHaveCount(1);
  });

  test('[HAPPY] a removed tag is undone in place: the tag is back in the row with its x', async ({ page }) => {
    const fixture = seed('some-assigned');
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const tagId = Number(await picture.removeButtons.first().getAttribute('data-tag-id'));
    await picture.clickRemoveButton(tagId);
    await expect(picture.assignedTag(tagId)).toHaveCount(0);
    await expect(picture.rowStatus).toHaveText(SAVED_WITH_UNDO);

    await picture.rowUndo.click();

    await expect(picture.rowStatus).toHaveText(UNDONE);
    await expect(picture.assignedTag(tagId)).toHaveCount(1);
    await expect(picture.removeButton(tagId)).toHaveCount(1);
    await expect(picture.addBadge(tagId)).toHaveCount(0);
  });

  test('[HAPPY] a typed new tag is undone: its link goes, its + badge appears', async ({ page }) => {
    const fixture = seed('no-tags');
    const name = `Undo ${RUN}`;
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await picture.addNewTag(name);
    const tag = trackTag(name);
    await expect(picture.assignedTag(tag.tag_id)).toHaveCount(1);
    await expect(picture.rowStatus).toHaveText(SAVED_WITH_UNDO);

    await picture.rowUndo.click();

    await expect(picture.rowStatus).toHaveText(UNDONE);
    await expect(picture.assignedTag(tag.tag_id)).toHaveCount(0);
    await expect(picture.addBadge(tag.tag_id)).toHaveCount(1);
  });

  test('[ST] an undo started in the change list updates the row', async ({ page }) => {
    const fixture = seed('some-assigned');
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const tagId = Number(await picture.addBadges.first().getAttribute('data-tag-id'));
    await picture.clickAddBadge(tagId);
    await expect(picture.assignedTag(tagId)).toHaveCount(1);
    await picture.historySummary.click();
    await expect(picture.historyEntries).toHaveCount(1);

    await picture.historyEntries.first().locator('.photoinfo-undo').click();

    await expect(picture.assignedTag(tagId)).toHaveCount(0);
    await expect(picture.addBadge(tagId)).toHaveCount(1);
    await expect(picture.rowStatus).toHaveText(UNDONE);
    await expect(picture.historyEntries).toHaveCount(2);
  });

  test('[NEG] an undo the tag moved past is refused in red on the row', async ({ page }) => {
    const fixture = seed('some-assigned');
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const tagId = Number(await picture.addBadges.first().getAttribute('data-tag-id'));
    await picture.clickAddBadge(tagId);
    await expect(picture.rowStatus).toHaveText(SAVED_WITH_UNDO);
    await picture.callWs({ method: 'typetags.image.removeTag', image_id: String(fixture.image_id), tag_id: String(tagId) });

    await picture.rowUndo.click();

    await expect(picture.rowStatus).toContainText('Wurde inzwischen von');
    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--error/);
    await expect(picture.assignedTag(tagId)).toHaveCount(1);
  });

  test('[ERR] a restored tag the page never showed is not reloaded in: the row says to reload', async ({ page }) => {
    const fixture = seed('some-assigned');
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await page.evaluate(() => { window.__notReloaded = true; });
    const tagId = Number(await picture.removeButtons.first().getAttribute('data-tag-id'));
    await picture.clickRemoveButton(tagId);
    await expect(picture.rowStatus).toHaveText(SAVED_WITH_UNDO);
    // forced: the page has no + badge for the tag, as when another tab removed it from the list
    await picture.addBadge(tagId).evaluate((el) => el.remove());
    await expect(picture.addBadge(tagId)).toHaveCount(0);

    await picture.rowUndo.click();

    await expect(picture.rowStatus).toContainText(UNDONE);
    await expect(picture.rowStatus).toContainText('Seite neu laden, um das wiederhergestellte Schlagwort zu sehen');
    await expect(picture.assignedTag(tagId)).toHaveCount(0);
    expect(await page.evaluate(() => window.__notReloaded)).toBe(true);
  });

  test('[NEG] an answer without a history entry shows "Gespeichert" and no Rückgängig', async ({ page }) => {
    const fixture = seed('some-assigned');
    const picture = new PicturePage(page);
    await page.route('**/ws.php*', async (route) => {
      if (!(route.request().postData() || '').includes('typetags.image.addTag')) {
        await route.continue();
        return;
      }
      // what photoinfo not being active answers: the same result without its field
      const response = await route.fetch();
      const json = await response.json();
      delete json.result.history_ids;
      await route.fulfill({ response, json });
    });
    await picture.gotoFixture(fixture);
    const tagId = Number(await picture.addBadges.first().getAttribute('data-tag-id'));

    await picture.clickAddBadge(tagId);

    await expect(picture.rowStatus).toHaveText(SAVED);
    await expect(picture.rowUndo).toHaveCount(0);
  });
});
