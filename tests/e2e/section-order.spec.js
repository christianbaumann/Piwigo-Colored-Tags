// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * [ERR] Findings 7a and 7b of the 2026-09-01 handbook-vs-live walkthrough.
 *
 * The oracle is the current implementation, not a requirement: these record
 * where typetags_picture_prefilter() and the theme's picture.tpl put things
 * today (events_public.inc.php:173-190, picture.tpl:210-217,300-303), so a
 * future move is visible in a run instead of only in a rewritten handbook
 * page. See ../../../../docs/agents/research/2026-09-01-handbuch-vs-live-deployment-findings.md
 * and handbuch/04-schlagworte.html.
 */
test.describe('picture page section order', () => {
  test.afterEach(async () => {
    restore();
  });

  test('the unassigned block follows the whole info list, not just the Tags row', async ({ page }) => {
    const fixture = seed('some-assigned');
    // Anti-vacuity: the fact only means something if there is a badge to find.
    expect(fixture.unassigned_colored_count).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await expect(picture.unassignedBox).toBeVisible();

    const order = await picture.unassignedBoxFollowsStandardInfoList();
    expect(order, 'dl#standard or #typetags-unassigned was not found').not.toBeNull();
    expect(order.following).toBe(true);
    expect(order.containedBy).toBe(false);
  });

  test('an assigned colored tag and an assigned plain tag share one row', async ({ page }) => {
    const fixture = seed('colored-and-plain');
    // Anti-vacuity: the shared-row fact only means something with exactly one
    // of each kind on the fixture.
    expect(fixture.assigned).toHaveLength(2);

    const picture = new PicturePage(page);
    await picture.gotoFixture(fixture);

    await expect(picture.assignedTags).toHaveCount(2);
    await expect(picture.tagsCell).toHaveCount(1);

    const painted = await picture.assignedBadgePaint();
    expect(painted).toHaveLength(2);
    expect(painted.filter((b) => b.badge)).toHaveLength(1);
    expect(painted.filter((b) => !b.badge)).toHaveLength(1);

    const separators = await picture.separatorTextNodes();
    expect(separators.some((text) => text.includes(','))).toBe(true);
  });
});
