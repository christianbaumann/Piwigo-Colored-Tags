<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The executable role matrix of the Colored Tags plugin: one row per function
 * and role, asserting the level the role gets.
 *
 * The expected levels are the owner's target matrix of 2026-10-12
 * (Piwigo .agents/changes/2026-10-12-role-test-coverage/design.md, decisions
 * 0044 and 0064), not read off the code. A probe sends the function's real
 * requests as one role and reports what it observed:
 *
 *   content shown | control shown | ws accepted | observed
 *   no            | no            | no          | none
 *   yes           | no            | no          | see
 *   yes           | yes           | yes         | edit
 *   anything else                               | INCONSISTENT: ...
 *
 * A part a function does not have is left out of its probe. Every part is one
 * or more named checks, and they must agree: a ws call that is refused but
 * changes the database anyway, or a control on one page and not on the other,
 * is INCONSISTENT, never a pass.
 *
 * `see*` is `see` reached only through the read-only page index.php?/taggroups
 * (decision 0064), with no read method: typetags.groups for normal and generic.
 *
 * The dimensions that combine with role (private album, grant, the daily cap)
 * stay in the feature files, VisibilityTest and CreationCapTest.
 */
final class RoleMatrixTest extends TestCase
{
    /** The plugin's ws methods all start with this; the guard reads it. */
    public const METHOD_PREFIX = 'typetags.';

    /** reflection.getMethodList listed 7 methods under the prefix, measured 2026-10-12. */
    private const MIN_PLUGIN_METHODS = 7;

    /** A rendered page shorter than this is an error page or a redirect stub. */
    private const MIN_PAGE_BYTES = 2000;

    private const GUEST = 'guest';

    /** role => account, in the column order of the matrix; the guest has none */
    private const ROLES = array(
        self::GUEST => null,
        'generic' => 'typetags_generic',
        'normal' => 'typetags_normal',
        'admin' => 'typetags_admin',
        'webmaster' => 'typetags_webmaster',
        );

    /**
     * The target matrix: function => (ws methods its probe calls, level per role).
     * generic always equals normal.
     */
    private const MATRIX = array(
        'typetags.badges' => array(
            'methods' => array(),
            'levels' => array('guest' => 'none', 'generic' => 'see', 'normal' => 'see', 'admin' => 'see', 'webmaster' => 'see'),
            ),
        'typetags.assign-remove' => array(
            'methods' => array('typetags.image.addTag', 'typetags.image.removeTag'),
            'levels' => array('guest' => 'none', 'generic' => 'edit', 'normal' => 'edit', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'typetags.type-new-tag' => array(
            'methods' => array('typetags.image.addNewTag'),
            'levels' => array('guest' => 'none', 'generic' => 'edit', 'normal' => 'edit', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'typetags.write-warning' => array(
            'methods' => array('typetags.image.addTag'),
            'levels' => array('guest' => 'none', 'generic' => 'see', 'normal' => 'see', 'admin' => 'see', 'webmaster' => 'see'),
            ),
        'typetags.groups' => array(
            'methods' => array('typetags.type.list', 'typetags.type.add', 'typetags.type.update', 'typetags.tags.setType'),
            'levels' => array('guest' => 'none', 'generic' => 'see*', 'normal' => 'see*', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'typetags.admin-chips' => array(
            'methods' => array(),
            'levels' => array('guest' => 'none', 'generic' => 'none', 'normal' => 'none', 'admin' => 'see', 'webmaster' => 'see'),
            ),
        );

    /** A colour the fixture group never has, for typetags.type.update. */
    private const UPDATED_COLOR = '#0a0b0c';

    /** @var WsClient[] one session per role, kept for the whole run */
    private static array $clients = array();

    private Db $db;
    private FixtureBuilder $fixtures;
    private string $suffix;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixtures = new FixtureBuilder($this->db);
        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->fixtures->trackTagNamed($this->fixtureName('tag'));
        $this->fixtures->trackGroupNamed($this->fixtureName('group'));
        $this->fixtures->restore();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$clients as $role => $client)
        {
            if ($role !== self::GUEST)
            {
                $client->logout();
            }
        }
        self::$clients = array();
    }

    /**
     * One row per cell. Technique: [NEG] guest; [ECP] generic, the equivalence
     * class of normal; [BVA] a role whose level differs from the role before it;
     * [HAPPY] every other row.
     */
    public static function matrixRows(): array
    {
        $rows = array();
        foreach (self::MATRIX as $function => $row)
        {
            $previous = null;
            foreach ($row['levels'] as $role => $level)
            {
                if ($role === self::GUEST)
                {
                    $tag = '[NEG]';
                }
                elseif ($role === 'generic')
                {
                    $tag = '[ECP]';
                }
                else
                {
                    $tag = $level !== $previous ? '[BVA]' : '[HAPPY]';
                }
                $previous = $level;
                $rows["$tag $function as $role"] = array($function, $role, $level);
            }
        }
        return $rows;
    }

    /** One cell of the matrix: the role gets exactly its level, every part agreeing. */
    #[DataProvider('matrixRows')]
    public function testTheRoleGetsItsLevel(string $function, string $role, string $expected): void
    {
        $parts = match ($function)
        {
            'typetags.badges' => $this->probeBadges($role),
            'typetags.assign-remove' => $this->probeAssignRemove($role),
            'typetags.type-new-tag' => $this->probeTypeNewTag($role),
            'typetags.write-warning' => $this->probeWriteWarning($role),
            'typetags.groups' => $this->probeGroups($role),
            'typetags.admin-chips' => $this->probeAdminChips($role),
        };

        $observed = self::level($parts);
        if ($function === 'typetags.groups' and $observed === 'see')
        {
            // The groups' only content is the read-only page (0064).
            $observed = 'see*';
        }

        $this->assertSame($expected, $observed, "$function as $role");
    }

    /**
     * [NEG] Every ws method of the plugin is declared by a matrix row: a new
     * method without role rows fails here, by name.
     */
    public function testEveryPluginMethodHasAMatrixRow(): void
    {
        $res = $this->client('webmaster')->call('reflection.getMethodList');
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $methods = array_values(array_filter(
            $res['json']['result']['methods'],
            fn ($name) => str_starts_with($name, self::METHOD_PREFIX)
        ));
        $this->assertGreaterThanOrEqual(self::MIN_PLUGIN_METHODS, count($methods), 'anti-vacuity: too few ' . self::METHOD_PREFIX . ' methods listed');

        $declared = array_merge(...array_column(self::MATRIX, 'methods'));
        foreach ($methods as $method)
        {
            $this->assertContains($method, $declared, "$method has no row in the role matrix");
        }
    }

    // ── Probes ────────────────────────────────────────────────────────────

    /** A coloured tag's badge on the picture page. Nothing to change, no method. */
    private function probeBadges(string $role): array
    {
        $striped = $this->fixtures->createStripedGroup();
        $imageId = $this->photoWith(array($striped['tag_id']));
        $page = $this->picturePage($role, $imageId);

        // The badge render_tag_name paints, not the data-tag-id the assignment prefilter adds:
        // that one is assign-remove's, and would tie this row to that gate.
        $name = (string)$this->db->scalar('SELECT name FROM piwigo_tags WHERE id = ' . $striped['tag_id']);
        return array('content' => array(
            'badge on the picture page' => str_contains($page, '<span style="'
                . typetags_badge_style(FixtureBuilder::STRIPED_GROUP_COLOR, true) . '">'
                . typetags_badge_label($name, FixtureBuilder::STRIPED_GROUP_EMOJI) . '</span>'),
            ));
    }

    /** The + badges and the two methods behind them (the x is built by script, E2E's). */
    private function probeAssignRemove(string $role): array
    {
        $striped = $this->fixtures->createStripedGroup();
        $other = $this->otherColoredTag($striped['tag_id']);
        $imageId = $this->photoWith(array($striped['tag_id']));
        $page = $this->picturePage($role, $imageId);

        $add = $this->call($role, 'typetags.image.addTag', array('image_id' => $imageId, 'tag_id' => $other));
        $added = in_array($other, $this->fixtures->assignedTagIds($imageId), true);
        $remove = $this->call($role, 'typetags.image.removeTag', array('image_id' => $imageId, 'tag_id' => $striped['tag_id']));
        $removed = !in_array($striped['tag_id'], $this->fixtures->assignedTagIds($imageId), true);

        return array(
            'content' => array('assigned badge' => str_contains($page, 'data-tag-id="' . $striped['tag_id'] . '"><span style="')),
            'control' => array('+ badge' => str_contains($page, '<span class="typetag-badge typetag-add" data-tag-id="' . $other . '"')),
            'ws' => array(
                'addTag answered ok' => self::ok($add),
                'addTag linked the tag' => $added,
                'removeTag answered ok' => self::ok($remove),
                'removeTag unlinked the tag' => $removed,
                ),
            );
    }

    /** The field for a new tag and typetags.image.addNewTag, below the daily cap. */
    private function probeTypeNewTag(string $role): array
    {
        $imageId = $this->photoWith(array());
        if ($role !== self::GUEST)
        {
            $this->assertLessThan(TYPETAGS_NEW_TAGS_PER_DAY, $this->typedTagsToday(self::ROLES[$role]),
                'precondition: ' . self::ROLES[$role] . ' is below the daily cap');
        }
        $page = $this->picturePage($role, $imageId);

        $res = $this->call($role, 'typetags.image.addNewTag', array('image_id' => $imageId, 'tag_name' => $this->fixtureName('tag')));
        $tagId = $this->db->scalar("SELECT id FROM piwigo_tags WHERE name = '" . $this->db->escape($this->fixtureName('tag')) . "'");

        return array(
            'content' => array('the photo' => str_contains($page, 'id="theMainImage"')),
            'control' => array('new tag field' => str_contains($page, 'id="typetags-new-tag"')),
            'ws' => array(
                'addNewTag answered ok' => self::ok($res),
                'addNewTag created and linked the tag' => $tagId !== null
                    and $this->fixtures->assignedTagIds($imageId) === array((int)$tagId),
                ),
            );
    }

    /**
     * The warning that a tag was saved but not written into the file: its slot
     * on the page, and the reason in the answer, with the file write forced to
     * fail through plugins/photoinfo. No control and no method of its own: the
     * answer is addTag's (assign-remove's row).
     */
    private function probeWriteWarning(string $role): array
    {
        $this->assertTrue($this->fixtures->pluginIsActive('photoinfo'),
            'typetags.write-warning needs plugins/photoinfo active: it writes the tags into the file');
        $striped = $this->fixtures->createStripedGroup();
        $imageId = $this->photoWith(array());
        $this->fixtures->makeTestImageReadOnly();
        $page = $this->picturePage($role, $imageId);

        $res = $this->call($role, 'typetags.image.addTag', array('image_id' => $imageId, 'tag_id' => $striped['tag_id']));
        $result = self::ok($res) ? (array)$res['json']['result'] : array();

        return array('content' => array(
            'warning slot' => str_contains($page, 'class="typetags-tag-warning"'),
            'answer says not written' => ($result['tags_written'] ?? null) === false
                and (string)($result['tags_message_l10n'] ?? '') !== '',
            ));
    }

    /**
     * The colour groups: read on index.php?/taggroups, changed on the admin tags
     * page and the plugin page through the four admin_only methods.
     */
    private function probeGroups(string $role): array
    {
        $striped = $this->fixtures->createStripedGroup();
        $groupId = $striped['group_id'];
        $groups = $this->page($role, '/index.php?/taggroups');
        $tags = $this->page($role, '/admin.php?page=tags');
        $plugin = $this->page($role, '/admin.php?page=plugin-typetags');

        $list = $this->call($role, 'typetags.type.list', array());
        $add = $this->call($role, 'typetags.type.add', array('typetag_name' => $this->fixtureName('group'), 'typetag_color' => 'AABBCC'));
        $update = $this->call($role, 'typetags.type.update', array('typetag_id' => $groupId, 'typetag_color' => self::UPDATED_COLOR));
        $setType = $this->call($role, 'typetags.tags.setType', array('tag_id' => $striped['tag_id'], 'typetag_id' => 0));

        return array(
            'content' => array('group on the read-only page' => str_contains($groups, 'data-group-id="' . $groupId . '"')),
            'control' => array(
                'colour button on the admin tags page' => str_contains($tags, 'id="TypetagsChangeColor"'),
                'form on the plugin page' => str_contains($plugin, 'name="addtypetag"'),
                ),
            'ws' => array(
                'type.list answered the group' => self::ok($list)
                    and in_array($groupId, array_column((array)$list['json']['result'], 'id'), true),
                'type.add answered ok' => self::ok($add),
                'type.add wrote the group' => $this->fixtures->trackGroupNamed($this->fixtureName('group')) !== null,
                'type.update answered ok' => self::ok($update),
                'type.update changed the colour' => $this->db->scalar("SELECT color FROM piwigo_typetags WHERE id = $groupId") === self::UPDATED_COLOR,
                'tags.setType answered ok' => self::ok($setType),
                'tags.setType took the tag out of the group' => $this->db->scalar('SELECT id_typetags FROM piwigo_tags WHERE id = ' . $striped['tag_id']) === null,
                ),
            );
    }

    /** The coloured tag chips of the admin photo page and the Batch Manager. */
    private function probeAdminChips(string $role): array
    {
        $striped = $this->fixtures->createStripedGroup();
        $imageId = $this->photoWith(array($striped['tag_id']));
        $chip = typetags_chip_css($striped['tag_id'], FixtureBuilder::STRIPED_GROUP_COLOR, 1, FixtureBuilder::STRIPED_GROUP_EMOJI);

        return array('content' => array(
            'chip on the photo page' => str_contains($this->page($role, '/admin.php?page=photo-' . $imageId), $chip),
            'chip in the Batch Manager' => str_contains($this->page($role, '/admin.php?page=batch_manager'), $chip),
            ));
    }

    // ── Observation ───────────────────────────────────────────────────────

    /**
     * The level a probe observed. Each part's checks must agree; a part the
     * function lacks is absent.
     *
     * @param array<string, array<string, bool>> $parts
     */
    private static function level(array $parts): string
    {
        $seen = array();
        foreach ($parts as $part => $checks)
        {
            $values = array_unique(array_values($checks));
            if (count($values) !== 1)
            {
                return "INCONSISTENT: $part disagrees: " . json_encode($checks);
            }
            $seen[$part] = $values[0];
        }

        $content = $seen['content'] ?? false;
        $control = $seen['control'] ?? null;
        $ws = $seen['ws'] ?? null;

        if (!$content and $control !== true and $ws !== true)
        {
            return 'none';
        }
        if ($content and $control !== true and $ws !== true)
        {
            return 'see';
        }
        if ($content and $control !== false and $ws !== false)
        {
            return 'edit';
        }
        return 'INCONSISTENT: ' . json_encode($parts);
    }

    // ── Requests ──────────────────────────────────────────────────────────

    /** The role's session, logged in once per run; the guest's never logs in. */
    private function client(string $role): WsClient
    {
        if (!isset(self::$clients[$role]))
        {
            $client = new WsClient();
            if ($role !== self::GUEST)
            {
                list($username, $password) = Config::account(self::ROLES[$role]);
                $client->login($username, $password);
            }
            self::$clients[$role] = $client;
        }
        return self::$clients[$role];
    }

    /**
     * A page as the role sees it. A refusal (a guest sent to the login page,
     * core's 401 page for a non-administrator on admin.php) reads as an empty
     * page; anything else must have rendered, or an absent marker says nothing.
     */
    private function page(string $role, string $path): string
    {
        $res = $this->client($role)->fetchPage($path);
        $body = (string)$res['body'];
        $this->assertStringNotContainsString('Fatal error', $body, "$path as $role");

        $refused = str_contains((string)$res['url'], 'identification.php') || $res['http_code'] === 401;
        if ($role === self::GUEST)
        {
            $this->assertStringContainsString('identification.php', (string)$res['url'], "$path as a guest");
        }
        if ($refused)
        {
            return '';
        }
        $this->assertSame(200, $res['http_code'], "$path as $role");
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), "anti-vacuity: $path as $role rendered a page");
        return $body;
    }

    /** The picture page without its scripts: the injected script spells every marker as a string. */
    private function picturePage(string $role, int $imageId): string
    {
        $categoryId = $this->fixtures->categoryIdFor($imageId);
        return preg_replace('#<script\b.*?</script>#is', '', $this->page($role, "/picture.php?/$imageId/category/$categoryId"));
    }

    private function call(string $role, string $method, array $params): array
    {
        $client = $this->client($role);
        return $client->call($method, $params + array('pwg_token' => $client->token()));
    }

    private static function ok(array $res): bool
    {
        return ($res['json']['stat'] ?? null) === 'ok';
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** The suite's throwaway photo, carrying exactly these tags. */
    private function photoWith(array $tagIds): int
    {
        $imageId = $this->fixtures->testImageId();
        $this->fixtures->imageWithNoTags($imageId);
        $this->fixtures->givenAssigned($imageId, $tagIds);
        $this->assertSame($tagIds, $this->fixtures->assignedTagIds($imageId), 'precondition: the photo carries the fixture tags');
        return $imageId;
    }

    /** A coloured tag of the install other than the fixture's, offered as a + badge. */
    private function otherColoredTag(int $exclude): int
    {
        $others = array_values(array_diff($this->fixtures->coloredTagIds(), array($exclude)));
        $this->assertNotEmpty($others, 'precondition: the install has a coloured tag besides the fixture');
        return $others[0];
    }

    /** What typetags_typed_tags_today() counts for an account. */
    private function typedTagsToday(string $username): int
    {
        return (int)$this->db->scalar("
SELECT COUNT(*)
  FROM piwigo_activity AS a
    INNER JOIN piwigo_users AS u ON u.id = a.performed_by
  WHERE u.username = '" . $this->db->escape($username) . "'
    AND a.object = 'tag' AND a.action = 'add'
    AND a.occured_on > NOW() - INTERVAL 1 DAY
    AND a.details LIKE '%\"typetags.image.addNewTag\"%'");
    }

    private function fixtureName(string $what): string
    {
        return "Rollenmatrix $what {$this->suffix}";
    }
}
