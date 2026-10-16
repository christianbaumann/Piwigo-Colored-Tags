// @ts-check
const { test, expect } = require('@playwright/test');
const { AdminTagsPage } = require('./support/AdminTagsPage');
const { PluginAdminPage } = require('./support/PluginAdminPage');
const { seed, restore, deleteTypetag } = require('./support/seed');

/**
 * Every group write a browser makes, after they all became POST with pwg_token
 * (task 09): CsrfTest proves a request without the token is refused; only a
 * browser proves the page's own forms and tags.js still carry it.
 */
test.describe('the group writes of the admin screens carry their token', () => {
  const NAME = '_test_e2e_group_lifecycle';
  const RENAMED = '_test_e2e_group_lifecycle_renamed';

  test.afterEach(() => {
    deleteTypetag(NAME);
    deleteTypetag(RENAMED);
    restore();
  });

  test('the plugin page creates, edits and deletes a group', async ({ page }) => {
    const plugin = new PluginAdminPage(page);
    await plugin.open();
    await expect(plugin.group(NAME)).toHaveCount(0);

    await plugin.add(NAME, '#123456');
    await expect(plugin.group(NAME)).toHaveCount(1);
    await expect(plugin.group(NAME)).toHaveAttribute('style', /#123456/i);

    await plugin.edit(NAME, RENAMED, '#654321');
    await expect(plugin.group(RENAMED)).toHaveCount(1);
    await expect(plugin.group(RENAMED)).toHaveAttribute('style', /#654321/i);

    await plugin.delete(RENAMED);
    await expect(plugin.group(RENAMED)).toHaveCount(0);
    // a reload shows the database, not what the page last drew
    await plugin.open();
    await expect(plugin.group(RENAMED)).toHaveCount(0);
  });

  test('the tags screen creates a group and regroups a tag', async ({ page }) => {
    const fixture = seed('no-tags', null, { stripedGroup: true });
    const tagId = fixture.striped_tag_id;

    const tags = new AdminTagsPage(page);
    await tags.open();
    await tags.search(fixture.striped_tag_name);
    await expect(tags.tagColorSample(tagId)).toHaveCount(1);

    await tags.enterSelectionMode();
    await tags.selectTag(tagId);
    await tags.openColorPanel();

    const created = page.waitForResponse((r) => r.url().includes('ws.php') && r.request().postData()?.includes('typetags.type.add') === true);
    await tags.createColor(NAME, '#123456');
    expect((await (await created).json()).stat).toBe('ok');
    await expect(tags.createSuccessMessage).toBeVisible();

    const regrouped = page.waitForResponse((r) => r.url().includes('ws.php') && r.request().postData()?.includes('typetags.tags.setType') === true);
    await tags.chooseColor('n');
    await tags.validateColor();
    expect((await (await regrouped).json()).stat).toBe('ok');
    await expect(tags.saveMessage).toBeVisible();

    await tags.open();
    await tags.search(fixture.striped_tag_name);
    await expect(tags.tagBox(tagId)).toBeVisible();
    await expect(tags.tagColorSample(tagId)).toHaveCount(0);
  });
});
