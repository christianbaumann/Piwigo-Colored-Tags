<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The picture page's tag methods act only on a photo the account may see.
 * Any logged-in account may tag (decision 0005), but a photo in an album it
 * has no access to is answered as if it did not exist. Core's rule, which
 * holds for administrators too: a private album needs a grant.
 *
 * Requirement: plan 2026-10-09 "Tags in the image file", owner's answer Q17.
 *
 * Every case runs once per logged-in role. The public album is the role
 * matrix's (RoleMatrixTest); private and private-with-a-grant are the
 * boundaries this file adds to it.
 */
final class VisibilityTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $imageId;
    private int $coloredTagId;
    private string $suffix;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);

        $colored = $this->fixtures->coloredTagIds();
        if (count($colored) === 0)
        {
            $this->markTestSkipped('No colored tag fixture available on this install');
        }
        $this->coloredTagId = $colored[0];
        $this->suffix = bin2hex(random_bytes(4));

        $this->imageId = $this->fixtures->testImageId();
        $this->fixtures->imageWithNoTags($this->imageId);
        $this->fixtures->makeTestAlbumPrivate();
    }

    protected function tearDown(): void
    {
        $this->fixtures->trackTagNamed($this->typedName());
        $this->fixtures->restore();
        $this->ws->logout();
    }

    /** [NEG][BVA] An account cannot add a tag to a photo it cannot see, an administrator included. */
    #[DataProvider('accounts')]
    public function testAddTagRefusesAPhotoTheAccountCannotSee(string $account): void
    {
        $this->login($account);

        $res = $this->call('typetags.image.addTag', array('tag_id' => $this->coloredTagId));

        $this->assertNotFound($res);
        $this->assertSame(array(), $this->fixtures->assignedTagIds($this->imageId));
    }

    /** [NEG] Nor remove one. */
    #[DataProvider('accounts')]
    public function testRemoveTagRefusesAPhotoTheAccountCannotSee(string $account): void
    {
        $this->fixtures->givenAssigned($this->imageId, array($this->coloredTagId));
        $this->login($account);

        $res = $this->call('typetags.image.removeTag', array('tag_id' => $this->coloredTagId));

        $this->assertNotFound($res);
        $this->assertSame(array($this->coloredTagId), $this->fixtures->assignedTagIds($this->imageId));
    }

    /** [NEG] Nor type a new one, which would also create the tag. */
    #[DataProvider('accounts')]
    public function testAddNewTagRefusesAPhotoTheAccountCannotSee(string $account): void
    {
        $this->login($account);

        $res = $this->call('typetags.image.addNewTag', array('tag_name' => $this->typedName()));

        $this->assertNotFound($res);
        $this->assertSame(0, (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_tags WHERE name = '" . $this->db->escape($this->typedName()) . "'"
        ));
    }

    /** [BVA] The same account, once granted the album, tags the photo. */
    #[DataProvider('accounts')]
    public function testAnAccountGrantedTheAlbumTagsThePhoto(string $account): void
    {
        $this->fixtures->grantTestAlbumTo($account);
        $this->login($account);

        $this->assertSame('ok', $this->call('typetags.image.addTag', array('tag_id' => $this->coloredTagId))['json']['stat'] ?? null);
        $this->assertSame('ok', $this->call('typetags.image.addNewTag', array('tag_name' => $this->typedName()))['json']['stat'] ?? null);
        $this->assertSame('ok', $this->call('typetags.image.removeTag', array('tag_id' => $this->coloredTagId))['json']['stat'] ?? null);
    }

    /** Every logged-in role; the guest is refused before visibility matters (RoleMatrixTest). */
    public static function accounts(): array
    {
        return array(
            'generic' => array('typetags_generic'),
            'normal' => array('typetags_normal'),
            'admin' => array('typetags_admin'),
            'webmaster' => array('typetags_webmaster'),
            );
    }

    private function login(string $account): void
    {
        list($username, $password) = Config::account($account);
        $this->ws->login($username, $password);
    }

    private function typedName(): string
    {
        return 'Kirmes ' . $this->suffix;
    }

    private function call(string $method, array $params): array
    {
        return $this->ws->call($method, $params + array(
            'image_id' => $this->imageId,
            'pwg_token' => $this->ws->token(),
        ));
    }

    private function assertNotFound(array $res): void
    {
        $this->assertSame('fail', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        $this->assertSame(404, $res['json']['err']);
    }
}
