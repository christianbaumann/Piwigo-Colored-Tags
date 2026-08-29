// @ts-check

/**
 * Page object for picture.php with the colored-tag assignment UI on it.
 *
 * Every locator in the E2E suite lives here. Specs orchestrate and assert; a
 * locator appearing in a spec file is the first step toward a suite nobody can
 * maintain, because the same selector then has to be corrected in N places.
 *
 * Selector policy: locate by the stable ids and classes the plugin emits on
 * purpose — #Tags, #typetags-unassigned, .typetag-add, .typetag-remove,
 * a[data-tag-id] — never by position within theme-generated markup.
 */
class PicturePage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.tagsRow = page.locator('#Tags');
    this.tagsCell = page.locator('#Tags dd');
    this.assignedTags = page.locator('#Tags dd a[data-tag-id]');
    this.removeButtons = page.locator('.typetag-remove');
    this.unassignedBox = page.locator('#typetags-unassigned');
    this.addBadges = page.locator('.typetag-add');
  }

  /** @param {{image_id: number, category_id: number}} fixture */
  async gotoFixture(fixture) {
    await this.page.goto(`/picture.php?/${fixture.image_id}/category/${fixture.category_id}`);
    // The remove buttons are appended by the injected footer script, so the DOM
    // a spec asserts against only exists once that script has run.
    await this.page.waitForLoadState('domcontentloaded');
  }

  async reload() {
    await this.page.reload();
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** @param {number} tagId */
  addBadge(tagId) {
    return this.page.locator(`.typetag-add[data-tag-id="${tagId}"]`);
  }

  /** @param {number} tagId */
  removeButton(tagId) {
    return this.page.locator(`.typetag-remove[data-tag-id="${tagId}"]`);
  }

  /** @param {number} tagId */
  assignedTag(tagId) {
    return this.page.locator(`#Tags dd a[data-tag-id="${tagId}"]`);
  }

  /**
   * The tag's display name, read from the badge the server rendered rather than
   * hardcoded in a spec — the names come from the live tag table.
   *
   * @param {number} tagId
   */
  async unassignedTagName(tagId) {
    return this.addBadge(tagId).getAttribute('data-tag-name');
  }

  /**
   * Whether #Tags sits before #Categories in document order.
   *
   * The add path creates the Tags row with albums.before(tagsDiv), so placement
   * is behaviour the JS chose, not something the theme guarantees.
   */
  async tagsRowPrecedesCategories() {
    return this.page.evaluate(() => {
      const tags = document.querySelector('#Tags');
      const categories = document.querySelector('#Categories');
      if (!tags || !categories) {
        return null;
      }
      return !!(tags.compareDocumentPosition(categories) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
  }

  /** Theme directories referenced by the page's stylesheets and scripts. */
  async loadedThemePaths() {
    return this.page.evaluate(() =>
      Array.from(document.querySelectorAll('link[href], script[src]'))
        .map((el) => el.getAttribute('href') || el.getAttribute('src') || '')
        .map((url) => (url.match(/themes\/([a-z_0-9-]+)/) || [])[1])
        .filter((name, i, all) => name && all.indexOf(name) === i)
    );
  }

  /**
   * Names of the assigned tags, with the nested × stripped.
   *
   * The remove button is a child span *inside* the badge span, so the anchor's
   * raw textContent reads "Personen ×" rather than "Personen".
   */
  async assignedNames() {
    return this.assignedTags.evaluateAll((anchors) =>
      anchors.map((a) => {
        const clone = /** @type {HTMLElement} */ (a.cloneNode(true));
        clone.querySelectorAll('.typetag-remove').forEach((x) => x.remove());
        return clone.textContent.trim();
      })
    );
  }

  /** Assigned tag ids in document order, so a spec can address first and last. */
  async assignedTagIds() {
    return this.assignedTags.evaluateAll((anchors) =>
      anchors.map((a) => Number(a.getAttribute('data-tag-id')))
    );
  }

  /** Labels of the unassigned badges, including the "+ " prefix as rendered. */
  async unassignedLabels() {
    return this.addBadges.evaluateAll((spans) => spans.map((s) => s.textContent.trim()));
  }

  /**
   * The raw text nodes sitting between the assigned tag anchors — this is where
   * the "," separators live, and where the remove path's previousSibling /
   * nextSibling logic does its cleanup.
   */
  async separatorTextNodes() {
    if ((await this.tagsCell.count()) === 0) {
      return [];
    }
    return this.tagsCell.evaluate((dd) =>
      Array.from(dd.childNodes)
        .filter((n) => n.nodeType === Node.TEXT_NODE)
        .map((n) => n.textContent)
    );
  }

  /**
   * What the browser actually paints for each unassigned badge: computed colours
   * and real geometry, not the style attribute the server wrote.
   *
   * This is the half of "does it look right on screen" that a machine can hold —
   * a badge whose CSS is overridden, collapsed to zero height, or painted the
   * wrong colour passes every DOM-shape assertion in the rest of the suite.
   */
  async unassignedBadgePaint() {
    return this.addBadges.evaluateAll((spans) =>
      spans.map((span) => {
        const style = window.getComputedStyle(span);
        const box = span.getBoundingClientRect();
        return {
          tagId: Number(span.getAttribute('data-tag-id')),
          backgroundColor: style.backgroundColor,
          color: style.color,
          width: box.width,
          height: box.height,
        };
      })
    );
  }

  /** The same, for the badge span nested inside each assigned tag anchor. */
  async assignedBadgePaint() {
    return this.assignedTags.evaluateAll((anchors) =>
      anchors.map((anchor) => {
        const badge = anchor.querySelector('span[style]');
        if (!badge) {
          // A non-coloured tag: the prefilter tagged the anchor, but no badge
          // span was ever generated for it.
          return { tagId: Number(anchor.getAttribute('data-tag-id')), badge: false };
        }
        const style = window.getComputedStyle(badge);
        const box = badge.getBoundingClientRect();
        return {
          tagId: Number(anchor.getAttribute('data-tag-id')),
          badge: true,
          backgroundColor: style.backgroundColor,
          color: style.color,
          width: box.width,
          height: box.height,
        };
      })
    );
  }

  /** True when #Tags exists in the DOM *and* is visible. */
  async tagsRowIsShown() {
    return (await this.tagsRow.count()) > 0 && (await this.tagsRow.isVisible());
  }

  /** True when #typetags-unassigned exists in the DOM *and* is visible. */
  async unassignedBoxIsShown() {
    return (await this.unassignedBox.count()) > 0 && (await this.unassignedBox.isVisible());
  }

  /** @param {import('@playwright/test').Locator} locator */
  static async computedOpacity(locator) {
    return locator.evaluate((el) => window.getComputedStyle(el).opacity);
  }

  /**
   * The inline pointer-events value, which is what the AJAX handlers toggle.
   * "none" while a request is in flight; restored to "" on completion — a badge
   * left at "none" is the dead-badge defect.
   *
   * @param {import('@playwright/test').Locator} locator
   */
  static async inlinePointerEvents(locator) {
    return locator.evaluate((el) => /** @type {HTMLElement} */ (el).style.pointerEvents);
  }
}

module.exports = { PicturePage };
