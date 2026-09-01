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

    // The "Add a new color" form inside the same panel (tags.tpl:59-77).
    this.newColorName = page.locator('#TypetagName');
    this.newColorHex = page.locator('#TypetagColor');
    this.createSuccessMessage = page.locator('.typetags-create-actions .typetag-message');
    this.createError = page.locator('.typetags-create-actions .typetag-error');

    // Every swatch in the palette, including the "Remove color" pseudo-option.
    // addColorOption() appends a clone of it, so a colour created in the
    // browser is one more of these and nothing else on the page changes.
    this.colorOptions = page.locator('.color-option-container .color-option');
  }

  /** The swatch a created colour produces, addressed by the id the server returned. */
  colorOption(/** @type {number|string} */ id) {
    return this.page.locator(`.color-option-container .color-option[data-id="${id}"]`);
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
   * Fill the two fields and press Create.
   *
   * The hex goes in with its leading hash, the way the field ships it
   * (tags.tpl:70, value="#444444"); tags.js:141 strips it before the call.
   *
   * @param {string} name
   * @param {string} hexWithHash
   */
  async createColor(name, hexWithHash) {
    await this.newColorName.fill(name);
    await this.newColorHex.fill(hexWithHash);
    await this.createButton.click();
  }

  /** The full documented path to the panel: selection mode, a tag, the button. */
  async walkToColorPanel() {
    await this.enterSelectionMode();
    await this.selectFirstTag();
    await this.openColorPanel();
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
