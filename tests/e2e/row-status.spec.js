// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore, trackTag } = require('./support/seed');

/**
 * The Schlagwörter row's save status (task 11, design "every step saves
 * itself visibly"): the class names and German strings are the same in all
 * plugins that render a row status.
 */
const SAVING = 'Speichert …';
/** The saved status; a save that wrote a history entry also offers Rückgängig. */
const SAVED = /^✓ Gespeichert( · Rückgängig)?$/;
const DIRTY = 'noch nicht gespeichert';

test.describe('the Schlagwörter row status', () => {
  test.afterEach(() => {
    restore();
  });

  test('[HAPPY] + shows saving, then saved, and - then saved again, without a reload', async ({ page }) => {
    const fixture = seed('some-assigned');
    expect(fixture.unassigned_colored_count).toBeGreaterThan(0);
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await expect(picture.rowStatus).toBeHidden();
    const tagId = await picture.addBadges.first().getAttribute('data-tag-id');

    // hold the answer so the "saving" state is observable
    await page.route('**/ws.php*', async (route) => {
      if ((route.request().postData() || '').includes('typetags.image.addTag')) {
        await new Promise((resolve) => setTimeout(resolve, 600));
      }
      await route.continue();
    });
    await picture.addBadge(tagId).click();
    await expect(picture.rowStatus).toHaveText(SAVING);
    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--saving/);
    await expect(picture.rowStatus).toHaveText(SAVED);
    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--saved/);
    await expect(picture.rowStatus).not.toHaveClass(/pwg-row-status--saving/);

    await picture.removeButton(tagId).click();
    await expect(picture.assignedTag(tagId)).toHaveCount(0);
    await expect(picture.rowStatus).toHaveText(SAVED);
  });

  test('[ST] typing shows "noch nicht gespeichert"; Enter saves, the field empties, saved stays', async ({ page }) => {
    const fixture = seed('some-assigned');
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await picture.newTagField.fill('_test_e2e_status');
    await expect(picture.rowStatus).toHaveText(DIRTY);
    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--dirty/);
    await picture.newTagField.fill('');
    await expect(picture.rowStatus).toBeHidden();

    await picture.newTagField.fill('_test_e2e_status');
    const answer = page.waitForResponse(
      (r) => r.url().includes('ws.php') && (r.request().postData() || '').includes('typetags.image.addNewTag')
    );
    await picture.newTagField.press('Enter');
    const result = await (await answer).json();
    expect(result.stat).toBe('ok');
    // recorded so restore() deletes the tag this case created
    trackTag('_test_e2e_status');
    await expect(picture.rowStatus).toHaveText(SAVED);
    await expect(picture.newTagField).toHaveValue('');
  });

  test('[ERR] a tag the file could not take is saved but stays red until the next change', async ({ page }) => {
    const fixture = seed('some-assigned', null, { readOnly: true });
    expect(fixture.unassigned_colored_count).toBeGreaterThan(1);
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    const ids = await picture.addBadges.evaluateAll((els) => els.map((e) => e.getAttribute('data-tag-id')));

    const answer = await picture.clickAddBadge(ids[0]);
    expect(answer.result.tags_written).toBe(false);
    await expect(picture.tagWarning).toHaveText(answer.result.tags_message_l10n);
    await expect(picture.tagWarning).toHaveClass(/pwg-row-status--error/);
    // one red status only: no green text beside it
    await expect(picture.rowStatus).toBeHidden();
    await expect(picture.rowStatus).not.toHaveText(SAVED);
    // stays: nothing clears it while the page is idle
    await page.waitForTimeout(1500);
    await expect(picture.tagWarning).toBeVisible();

    // the next change answers again and shows its own result
    const second = await picture.clickAddBadge(ids[1]);
    await expect(picture.tagWarning).toHaveText(second.result.tags_message_l10n);
    await expect(picture.rowStatus).toBeHidden();
  });
});
