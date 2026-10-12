// @ts-check

/**
 * Page object for the read-only colour group page, index.php?/taggroups, and
 * the link to it on core's tags page.
 */
class TagGroupsPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.tagsPageLink = page.locator('#typetags-groups-link a');
    this.groupList = page.locator('#typetags-groups');
    this.controls = this.groupList.locator('form, input, button, select, textarea');
  }

  async gotoTagsPage() {
    await this.page.goto('/tags.php');
  }

  async goto() {
    await this.page.goto('/index.php?/taggroups');
  }

  /** @param {number} groupId */
  group(groupId) {
    return this.groupList.locator(`.typetags-group[data-group-id="${groupId}"]`);
  }

  /** @param {number} groupId */
  emoji(groupId) {
    return this.group(groupId).locator('.typetags-group-title .typetag-emoji');
  }

  /** @param {number} groupId */
  name(groupId) {
    return this.group(groupId).locator('.typetags-group-name');
  }

  /**
   * @param {number} groupId
   * @param {number} tagId
   */
  badge(groupId, tagId) {
    return this.group(groupId).locator(`a[data-tag-id="${tagId}"] .typetag-badge`);
  }

  /**
   * The paint of one badge as the browser computed it.
   *
   * @param {number} groupId
   * @param {number} tagId
   */
  async badgePaint(groupId, tagId) {
    return this.badge(groupId, tagId).evaluate((el) => {
      const s = getComputedStyle(el);
      return {
        backgroundColor: s.backgroundColor,
        backgroundImage: s.backgroundImage,
        borderColor: s.borderLeftColor,
        color: s.color,
      };
    });
  }
}

module.exports = { TagGroupsPage };
