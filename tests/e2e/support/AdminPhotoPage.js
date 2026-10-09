// @ts-check

/**
 * The photo properties screen, as far as this plugin reaches into it: the
 * per-tag CSS typetags_admin_photo() appends to colour the tag field's chips.
 */
class AdminPhotoPage {
  static url(/** @type {number} */ imageId) {
    return `/admin.php?page=photo-${imageId}`;
  }

  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
  }

  /** One tag's chip in the selectize tag field. */
  chip(/** @type {number} */ tagId) {
    return this.page.locator(`.selectize-input .item[data-value="~~${tagId}~~"]`);
  }

  async open(/** @type {number} */ imageId) {
    await this.page.goto(AdminPhotoPage.url(imageId));
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** What the browser painted for one chip, including the emoji put before it. */
  async chipPaint(/** @type {number} */ tagId) {
    return this.chip(tagId).evaluate((el) => {
      const style = window.getComputedStyle(el);
      return {
        backgroundImage: style.backgroundImage,
        borderColor: style.borderLeftColor,
        color: style.color,
        before: window.getComputedStyle(el, '::before').content,
      };
    });
  }
}

module.exports = { AdminPhotoPage };
