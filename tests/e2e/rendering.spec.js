// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * What the page actually looks like once the browser has painted it.
 *
 * These automate the substance of two manual boxes Phase 4 opened — "watching a
 * headed run confirms the assertions describe what happens on screen" and
 * "rendering under the modus theme matches the reference screenshots". The rest
 * of the suite asserts DOM shape, which a badge can satisfy while being painted
 * the wrong colour, collapsed to zero height, or hidden behind something.
 *
 * Expected colours are read from the database through the seeding CLI, not
 * typed here: a second copy of the palette would rot the day only one is edited.
 *
 * What stays manual after this: whether the contrast is *legible* and whether
 * the hover transition *feels* right. Those have no oracle and remain in the
 * hand-check ledger.
 */

/** A badge smaller than this is collapsed, whatever its DOM says. */
const MIN_BADGE_WIDTH = 20;
const MIN_BADGE_HEIGHT = 10;

/** getComputedStyle only ever reports these two for the contrast text colour. */
const BLACK = 'rgb(0, 0, 0)';
const WHITE = 'rgb(255, 255, 255)';

test.describe('on-screen rendering', () => {
  test.afterEach(async () => {
    restore();
  });

  test('every unassigned badge paints its configured colour at a real size', async ({ page }) => {
    // An untagged image leaves the whole palette unassigned, so one page covers
    // every configured colour rather than whichever two a smaller fixture holds.
    const fixture = seed('no-tags');
    expect(fixture.colored_total).toBeGreaterThan(0);
    expect(fixture.unassigned_colored_count).toBe(fixture.colored_total);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    const painted = await picture.unassignedBadgePaint();
    expect(painted).toHaveLength(fixture.colored_total);

    for (const badge of painted) {
      const expected = fixture.colors[badge.tagId];
      expect(expected, `tag ${badge.tagId} is missing from the fixture palette`).toBeTruthy();
      expect(badge.backgroundColor, `tag ${badge.tagId} background`).toBe(expected.rgb);
      expect([BLACK, WHITE], `tag ${badge.tagId} text colour`).toContain(badge.color);
      expect(badge.width, `tag ${badge.tagId} width`).toBeGreaterThan(MIN_BADGE_WIDTH);
      expect(badge.height, `tag ${badge.tagId} height`).toBeGreaterThan(MIN_BADGE_HEIGHT);
    }
  });

  test('every assigned badge paints its configured colour at a real size', async ({ page }) => {
    const fixture = seed('all-assigned');
    expect(fixture.assigned_colored_count).toBe(fixture.colored_total);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    const painted = await picture.assignedBadgePaint();
    expect(painted).toHaveLength(fixture.colored_total);

    for (const badge of painted) {
      const expected = fixture.colors[badge.tagId];
      expect(expected, `tag ${badge.tagId} is missing from the fixture palette`).toBeTruthy();
      expect(badge.badge, `tag ${badge.tagId} rendered no badge span`).toBe(true);
      expect(badge.backgroundColor, `tag ${badge.tagId} background`).toBe(expected.rgb);
      expect([BLACK, WHITE], `tag ${badge.tagId} text colour`).toContain(badge.color);
      expect(badge.width, `tag ${badge.tagId} width`).toBeGreaterThan(MIN_BADGE_WIDTH);
      expect(badge.height, `tag ${badge.tagId} height`).toBeGreaterThan(MIN_BADGE_HEIGHT);
    }
  });

  test('a badge assigned in the browser is painted like a server-rendered one', async ({ page }) => {
    // The add path builds the badge as a JavaScript string literal, separately
    // from the PHP that renders one. Nothing but a browser can tell whether the
    // two agree, and a divergence would be invisible to every other assertion.
    const fixture = seed('some-assigned');
    const tagId = fixture.unassigned[0];

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);
    await picture.addBadge(tagId).click();
    await expect(picture.assignedTag(tagId)).toHaveCount(1);

    const painted = (await picture.assignedBadgePaint()).find((b) => b.tagId === tagId);
    expect(painted).toBeTruthy();
    expect(painted.backgroundColor).toBe(fixture.colors[tagId].rgb);
    expect([BLACK, WHITE]).toContain(painted.color);
    expect(painted.width).toBeGreaterThan(MIN_BADGE_WIDTH);
    expect(painted.height).toBeGreaterThan(MIN_BADGE_HEIGHT);

    await picture.reload();

    // Same tag, now rendered by PHP instead of by the injected script.
    const afterReload = (await picture.assignedBadgePaint()).find((b) => b.tagId === tagId);
    expect(afterReload.backgroundColor).toBe(painted.backgroundColor);
    expect(afterReload.color).toBe(painted.color);
  });

  test('the assignment UI initialises with no console or page errors', async ({ page }) => {
    const problems = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        problems.push(`console.error: ${msg.text()}`);
      }
    });
    page.on('pageerror', (error) => {
      problems.push(`pageerror: ${error.message}`);
    });

    const fixture = seed('some-assigned');
    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    // Anti-vacuity: a page that rendered no assignment UI at all would have no
    // script to throw, and this test would pass having watched nothing.
    await expect(picture.addBadges).toHaveCount(fixture.unassigned_colored_count);
    await expect(picture.removeButtons).toHaveCount(fixture.assigned_colored_count);

    expect(problems).toEqual([]);
  });
});
