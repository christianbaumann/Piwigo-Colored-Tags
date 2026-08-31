// @ts-check

/**
 * The tag administration screen, as far as this plugin reaches into it.
 *
 * Every locator the admin specs use lives here. The plugin injects its markup
 * into core's compiled template with a Smarty prefilter
 * (include/events_admin.inc.php:50-64), so these ids come from
 * plugins/typetags/template/tags.tpl even though the page is core's.
 */
class AdminTagsPage {
  static url() {
    return '/admin.php?page=tags';
  }

  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.tagNames = page.locator('.tag-name');

    // Core's own selection mode. The checkbox is styled away, so the label is
    // what a user can actually click.
    this.selectionModeSwitch = page.locator('.selection-mode-group-manager label.switch');
    this.selectionBlock = page.locator('#selection-mode-block');
    this.nothingSelected = page.locator('#nothing-selected');

    // Injected by the plugin.
    this.colorButton = page.locator('#TypetagsChangeColor');
    this.colorPanel = page.locator('#TypetagsOption');
    this.createButton = page.locator('#TypetagsCreate');
    this.removeColorLabel = page.locator('.color-option[data-id="n"] .color-name');
  }

  async open() {
    await this.page.goto(AdminTagsPage.url());
    await this.page.waitForLoadState('domcontentloaded');
    // The list is drawn from a data-tags payload; a spec that runs before it
    // lands would find no tag to select.
    await this.tagNames.first().waitFor({ state: 'visible' });
  }

  async enterSelectionMode() {
    await this.selectionModeSwitch.click();
    await this.selectionBlock.waitFor({ state: 'visible' });
  }

  async selectFirstTag() {
    await this.tagNames.first().click();
  }

  async openColorPanel() {
    await this.colorButton.click();
    await this.colorPanel.waitFor({ state: 'visible' });
  }

  /**
   * The text an element carries while it is still hidden.
   *
   * The oracle for "the browser did not rewrite this label": what the server
   * put in the element before any of the screen's JavaScript could act on it.
   * Reading it rather than typing the German keeps one copy of the wording, in
   * the language file, where GermanScreenTest already checks it.
   *
   * @param {import('@playwright/test').Locator} locator
   */
  static async serverText(locator) {
    return (await locator.textContent() || '').trim();
  }
}

module.exports = { AdminTagsPage };
