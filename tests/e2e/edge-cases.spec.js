// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

const WS_URL = '**/ws.php*';

/** True for the AJAX call the assignment UI makes, not for other ws.php traffic. */
function isTypetagsCall(request) {
  return request.method() === 'POST' && /method=typetags\.image\./.test(request.postData() || '');
}

/**
 * Failure modes and rendering details that only a browser can witness.
 *
 * Plan B checklist boxes 517, 552-555, 557, plus the server-rejection case,
 * which no box covers — it is the signature of the defect fixed in Phase 2.
 */
test.describe('edge cases', () => {
  test.afterEach(async () => {
    restore();
  });

  test('double-clicking issues exactly one request', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.unassigned[0];

    let calls = 0;
    await page.route(WS_URL, async (route, request) => {
      if (isTypetagsCall(request)) {
        calls += 1;
        // Hold the response open so both halves of the double-click land while
        // the first request is still in flight — otherwise the badge is already
        // gone and the test would pass for the wrong reason.
        await new Promise((resolve) => setTimeout(resolve, 700));
      }
      await route.continue();
    });

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await picture.addBadge(tagId).dblclick();

    await expect(picture.assignedTag(tagId)).toHaveCount(1);
    expect(calls).toBe(1);
    // And the UI is not glitched: the tag is in exactly one place.
    await expect(picture.addBadge(tagId)).toHaveCount(0);
  });

  test('a network failure leaves the tag in place and the badge clickable', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.unassigned[0];
    const before = fixture.unassigned_colored_count;

    await page.route(WS_URL, async (route, request) => {
      if (isTypetagsCall(request)) {
        await route.abort('failed');
        return;
      }
      await route.continue();
    });

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await picture.addBadge(tagId).click();

    // jQuery's error callback restores pointer-events, so the badge stays live.
    await expect
      .poll(() => PicturePage.inlinePointerEvents(picture.addBadge(tagId)))
      .toBe('');
    await expect(picture.addBadges).toHaveCount(before);
    await expect(picture.assignedTag(tagId)).toHaveCount(0);
  });

  test('a server rejection leaves the badge clickable', async ({ page }) => {
    const fixture = seed('some-assigned');
    const tagId = fixture.unassigned[0];

    const warnings = [];
    page.on('console', (msg) => {
      if (msg.type() === 'warning') {
        warnings.push(msg.text());
      }
    });

    await page.route(WS_URL, async (route, request) => {
      if (isTypetagsCall(request)) {
        // PwgError is delivered as HTTP 200 with stat:"fail", so this lands in
        // jQuery's success callback, not its error callback. Before the Phase 2
        // fix the success branch had no else and the badge stayed dead.
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ stat: 'fail', err: 403, message: 'Invalid security token' }),
        });
        return;
      }
      await route.continue();
    });

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await picture.addBadge(tagId).click();

    await expect
      .poll(() => PicturePage.inlinePointerEvents(picture.addBadge(tagId)))
      .toBe('');
    await expect(picture.assignedTag(tagId)).toHaveCount(0);
    await expect.poll(() => warnings.join('\n')).toContain('typetags: Invalid security token');
  });

  test('comma separators render between multiple assigned tags', async ({ page }) => {
    const fixture = seed('all-assigned');
    expect(fixture.assigned_colored_count).toBeGreaterThan(1);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    const separators = await picture.separatorTextNodes();
    expect(separators).toHaveLength(fixture.assigned_colored_count - 1);
    for (const separator of separators) {
      expect(separator.trim()).toBe(',');
    }
  });

  test('comma separators clean up with no leading or trailing comma', async ({ page }) => {
    const fixture = seed('all-assigned');
    const count = fixture.assigned_colored_count;
    expect(count).toBeGreaterThan(2); // room to remove from both ends

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    const orderedIds = await picture.assignedTagIds();
    expect(orderedIds).toHaveLength(count);

    // Remove from the front and the back, which exercises both cleanup branches:
    // the nextSibling one and the previousSibling one.
    await picture.removeButton(orderedIds[0]).click();
    await expect(picture.assignedTags).toHaveCount(count - 1);
    await picture.removeButton(orderedIds[orderedIds.length - 1]).click();
    await expect(picture.assignedTags).toHaveCount(count - 2);

    const text = (await picture.tagsCell.textContent()).trim();
    expect(text).not.toMatch(/^,/);
    expect(text).not.toMatch(/,$/);
    expect(text).not.toMatch(/,\s*,/);

    const rawTextNodes = await picture.separatorTextNodes();
    const separators = rawTextNodes.filter((node) => node.trim() !== '');
    expect(separators).toHaveLength(count - 3); // one per gap between the 6 survivors
    for (const separator of separators) {
      expect(separator.trim()).toBe(',');
    }

    // Characterization, no requirement behind it: the two cleanup branches are
    // not symmetric. The nextSibling branch removes the separator node; the
    // previousSibling branch, taken when the last tag goes, only empties its
    // text. That leaves exactly one zero-length text node behind — invisible,
    // and asserted here so a change to either branch shows up rather than
    // passing silently.
    expect(rawTextNodes.filter((node) => node.trim() === '')).toHaveLength(1);
  });

  test('an image with only non-coloured tags shows no remove buttons', async ({ page }) => {
    const fixture = seed('only-non-colored');
    expect(fixture.assigned_colored_count).toBe(0);
    expect(fixture.assigned).toHaveLength(1);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    // The plain tag renders, untouched: the anchor is there, no × on it.
    await expect(picture.assignedTags).toHaveCount(1);
    await expect(picture.removeButtons).toHaveCount(0);
    expect((await picture.assignedNames())[0]).toBeTruthy();
    // ... and every coloured tag is offered as unassigned.
    await expect(picture.addBadges).toHaveCount(fixture.colored_total);
  });

  test('the modus theme renders both sections correctly', async ({ page }) => {
    const fixture = seed('some-assigned');

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    // Guard against asserting modus rendering while some other theme is active,
    // which would make every assertion below true of the wrong page.
    expect(await picture.loadedThemePaths()).toContain('modus');

    expect(await picture.tagsRowIsShown()).toBe(true);
    expect(await picture.unassignedBoxIsShown()).toBe(true);
    await expect(picture.assignedTags).toHaveCount(fixture.assigned_colored_count);
    await expect(picture.addBadges).toHaveCount(fixture.unassigned_colored_count);
    await expect(picture.removeButtons).toHaveCount(fixture.assigned_colored_count);
  });
});
