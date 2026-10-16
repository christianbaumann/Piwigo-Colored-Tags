// @ts-check

/**
 * The plugin's own page, admin.php?page=plugin-typetags: the add / edit form
 * and the list of groups with their edit link and delete form (template/admin.tpl).
 */
class PluginAdminPage {
  static url() {
    return '/admin.php?page=plugin-typetags';
  }

  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.nameField = page.locator('input[name="typetag_name"]');
    this.colorField = page.locator('input[name="typetag_color"]');
    this.addButton = page.locator('input[name="addtypetag"]');
    this.saveButton = page.locator('input[name="edittypetag"]');
  }

  async open() {
    await this.page.goto(PluginAdminPage.url());
    await this.nameField.waitFor({ state: 'visible' });
  }

  /** The list entry of one group, by its name. */
  group(/** @type {string} */ name) {
    return this.page.locator('ul.typetagSelection li').filter({ hasText: name });
  }

  /** @param {string} name @param {string} hexWithHash */
  async add(name, hexWithHash) {
    await this.nameField.fill(name);
    await this.colorField.fill(hexWithHash);
    await Promise.all([this.page.waitForNavigation({ waitUntil: 'load' }), this.addButton.click()]);
  }

  /**
   * Open the edit form of a group and save it under another name and colour.
   * The icons are pressed with Enter: a long name wraps over them, and a click
   * then lands on the list entry (style.css, measured 2026-10-16).
   */
  async edit(/** @type {string} */ name, /** @type {string} */ newName, /** @type {string} */ hexWithHash) {
    await Promise.all([
      this.page.waitForNavigation({ waitUntil: 'load' }),
      this.group(name).locator('a[href*="edittypetag="]').press('Enter'),
    ]);
    await this.nameField.fill(newName);
    await this.colorField.fill(hexWithHash);
    await Promise.all([this.page.waitForNavigation({ waitUntil: 'load' }), this.saveButton.click()]);
  }

  /** Press a group's delete button and confirm the browser's question. */
  async delete(/** @type {string} */ name) {
    this.page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      this.page.waitForNavigation({ waitUntil: 'load' }),
      this.group(name).locator('input[name="deletetypetag"] ~ input[type="image"]').press('Enter'),
    ]);
  }
}

module.exports = { PluginAdminPage };
