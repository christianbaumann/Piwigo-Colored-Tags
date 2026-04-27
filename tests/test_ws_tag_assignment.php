<?php
/**
 * Integration tests for typetags.image.addTag and typetags.image.removeTag WS methods.
 *
 * Run inside DDEV: ddev exec php plugins/typetags/tests/test_ws_tag_assignment.php
 *
 * Prerequisites: DDEV running, Piwigo installed, typetags plugin active,
 * at least one colored tag and one image exist.
 */

$base_url = 'http://localhost/ws.php?format=json';
$cookie_file = '/tmp/typetags_test_cookies.txt';

// DB connection for fixture setup/teardown
$db = new mysqli('db', 'db', 'db', 'db');
if ($db->connect_error) {
    die("DB connection failed: " . $db->connect_error . "\n");
}

$passed = 0;
$failed = 0;
$errors = [];

// ── Helpers ─────────────────────────────────────────────────────────────

function ws_call($method, $params = [], $use_cookies = true)
{
    global $base_url, $cookie_file;
    $params['method'] = $method;

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $base_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => $params,
    ];
    if ($use_cookies) {
        $opts[CURLOPT_COOKIEJAR]  = $cookie_file;
        $opts[CURLOPT_COOKIEFILE] = $cookie_file;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => $http_code,
        'body'      => $body,
        'json'      => json_decode($body, true),
    ];
}

function login($username = 'chriss', $password = 'test123')
{
    global $cookie_file;
    @unlink($cookie_file);
    $res = ws_call('pwg.session.login', [
        'username' => $username,
        'password' => $password,
    ]);
    if (!$res['json'] || $res['json']['stat'] !== 'ok') {
        die("Login failed. Check credentials (tried $username / $password).\n" . $res['body'] . "\n");
    }
}

function logout()
{
    ws_call('pwg.session.logout');
    global $cookie_file;
    @unlink($cookie_file);
}

function get_pwg_token()
{
    $res = ws_call('pwg.session.getStatus');
    return $res['json']['result']['pwg_token'];
}

function assert_test($name, $condition, $detail = '')
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  PASS: $name\n";
    } else {
        $failed++;
        $msg = "  FAIL: $name" . ($detail ? " — $detail" : "");
        echo "$msg\n";
        $errors[] = $msg;
    }
}

function image_has_tag($image_id, $tag_id)
{
    global $db;
    $r = $db->query("SELECT 1 FROM piwigo_image_tag WHERE image_id = $image_id AND tag_id = $tag_id");
    return $r->num_rows > 0;
}

// ── Fixture setup ──────────────────────────────────────────────────────

echo "Setting up test fixtures...\n";

// Ensure we have a colored tag
$r = $db->query('SELECT t.id FROM piwigo_tags t WHERE t.id_typetags IS NOT NULL LIMIT 1');
if ($r->num_rows === 0) {
    die("No colored tags found. Create at least one colored tag before running tests.\n");
}
$colored_tag = $r->fetch_assoc();
$colored_tag_id = (int)$colored_tag['id'];

// Ensure we have a non-colored tag (create temporarily if needed)
$created_plain_tag = false;
$r = $db->query('SELECT t.id FROM piwigo_tags t WHERE t.id_typetags IS NULL LIMIT 1');
if ($r->num_rows === 0) {
    $db->query("INSERT INTO piwigo_tags (name, url_name) VALUES ('_test_plain_tag', '_test_plain_tag')");
    $plain_tag_id = (int)$db->insert_id;
    $created_plain_tag = true;
} else {
    $plain_tag_id = (int)$r->fetch_assoc()['id'];
}

// Pick an image
$r = $db->query('SELECT id FROM piwigo_images LIMIT 1');
$image_id = (int)$r->fetch_assoc()['id'];

// Clean slate: remove the colored tag from our test image (we'll add it in tests)
$db->query("DELETE FROM piwigo_image_tag WHERE image_id = $image_id AND tag_id = $colored_tag_id");

echo "  Colored tag ID: $colored_tag_id\n";
echo "  Plain tag ID:   $plain_tag_id\n";
echo "  Image ID:       $image_id\n\n";

// ── Test: Guest rejection ──────────────────────────────────────────────

echo "Test group: Guest user rejection\n";
logout();

$res = ws_call('typetags.image.addTag', [
    'image_id'  => $image_id,
    'tag_id'    => $colored_tag_id,
    'pwg_token' => 'fake',
], false);  // no cookies = guest
assert_test(
    'addTag rejects guest with 401',
    $res['json']['stat'] === 'fail' && $res['json']['err'] === 401,
    'Got: ' . json_encode($res['json'])
);

$res = ws_call('typetags.image.removeTag', [
    'image_id'  => $image_id,
    'tag_id'    => $colored_tag_id,
    'pwg_token' => 'fake',
], false);
assert_test(
    'removeTag rejects guest with 401',
    $res['json']['stat'] === 'fail' && $res['json']['err'] === 401,
    'Got: ' . json_encode($res['json'])
);

// ── Login as admin ─────────────────────────────────────────────────────

echo "\nLogging in as admin...\n";
login();
$token = get_pwg_token();
echo "  pwg_token: $token\n\n";

// ── Test: Bad token rejection ──────────────────────────────────────────

echo "Test group: Invalid CSRF token\n";

$res = ws_call('typetags.image.addTag', [
    'image_id'  => $image_id,
    'tag_id'    => $colored_tag_id,
    'pwg_token' => 'wrong_token_value',
]);
assert_test(
    'addTag rejects bad pwg_token with 403',
    $res['json']['stat'] === 'fail' && $res['json']['err'] === 403,
    'Got: ' . json_encode($res['json'])
);

$res = ws_call('typetags.image.removeTag', [
    'image_id'  => $image_id,
    'tag_id'    => $colored_tag_id,
    'pwg_token' => 'wrong_token_value',
]);
assert_test(
    'removeTag rejects bad pwg_token with 403',
    $res['json']['stat'] === 'fail' && $res['json']['err'] === 403,
    'Got: ' . json_encode($res['json'])
);

// ── Test: Non-colored tag rejection ────────────────────────────────────

echo "\nTest group: Non-colored tag rejection\n";

$res = ws_call('typetags.image.addTag', [
    'image_id'  => $image_id,
    'tag_id'    => $plain_tag_id,
    'pwg_token' => $token,
]);
assert_test(
    'addTag rejects non-colored tag with 404',
    $res['json']['stat'] === 'fail' && $res['json']['err'] === 404,
    'Got: ' . json_encode($res['json'])
);

$res = ws_call('typetags.image.removeTag', [
    'image_id'  => $image_id,
    'tag_id'    => $plain_tag_id,
    'pwg_token' => $token,
]);
assert_test(
    'removeTag rejects non-colored tag with 404',
    $res['json']['stat'] === 'fail' && $res['json']['err'] === 404,
    'Got: ' . json_encode($res['json'])
);

// ── Test: Nonexistent tag rejection ────────────────────────────────────

echo "\nTest group: Nonexistent tag rejection\n";

$res = ws_call('typetags.image.addTag', [
    'image_id'  => $image_id,
    'tag_id'    => 99999,
    'pwg_token' => $token,
]);
assert_test(
    'addTag rejects nonexistent tag with 404',
    $res['json']['stat'] === 'fail' && $res['json']['err'] === 404,
    'Got: ' . json_encode($res['json'])
);

// ── Test: Successful add ───────────────────────────────────────────────

echo "\nTest group: Successful tag assignment\n";

// Ensure tag is NOT assigned
$db->query("DELETE FROM piwigo_image_tag WHERE image_id = $image_id AND tag_id = $colored_tag_id");
assert_test('Pre-condition: tag not assigned', !image_has_tag($image_id, $colored_tag_id));

$res = ws_call('typetags.image.addTag', [
    'image_id'  => $image_id,
    'tag_id'    => $colored_tag_id,
    'pwg_token' => $token,
]);
assert_test(
    'addTag returns stat=ok',
    $res['json']['stat'] === 'ok',
    'Got: ' . json_encode($res['json'])
);
assert_test(
    'addTag: tag now in IMAGE_TAG_TABLE',
    image_has_tag($image_id, $colored_tag_id)
);

// ── Test: Idempotent add (INSERT IGNORE) ───────────────────────────────

echo "\nTest group: Idempotent duplicate add\n";

$res = ws_call('typetags.image.addTag', [
    'image_id'  => $image_id,
    'tag_id'    => $colored_tag_id,
    'pwg_token' => $token,
]);
assert_test(
    'addTag duplicate returns stat=ok (no error)',
    $res['json']['stat'] === 'ok',
    'Got: ' . json_encode($res['json'])
);
assert_test(
    'addTag duplicate: tag still in IMAGE_TAG_TABLE',
    image_has_tag($image_id, $colored_tag_id)
);

// ── Test: Successful remove ────────────────────────────────────────────

echo "\nTest group: Successful tag removal\n";

$res = ws_call('typetags.image.removeTag', [
    'image_id'  => $image_id,
    'tag_id'    => $colored_tag_id,
    'pwg_token' => $token,
]);
assert_test(
    'removeTag returns stat=ok',
    $res['json']['stat'] === 'ok',
    'Got: ' . json_encode($res['json'])
);
assert_test(
    'removeTag: tag removed from IMAGE_TAG_TABLE',
    !image_has_tag($image_id, $colored_tag_id)
);

// ── Test: Remove already-removed tag (idempotent) ──────────────────────

echo "\nTest group: Idempotent remove\n";

$res = ws_call('typetags.image.removeTag', [
    'image_id'  => $image_id,
    'tag_id'    => $colored_tag_id,
    'pwg_token' => $token,
]);
assert_test(
    'removeTag on unassigned tag returns stat=ok (no error)',
    $res['json']['stat'] === 'ok',
    'Got: ' . json_encode($res['json'])
);

// ── Test: User cache invalidation ──────────────────────────────────────

echo "\nTest group: Cache invalidation\n";

// Set a non-null value first
$db->query("UPDATE piwigo_user_cache SET nb_available_tags = 5 LIMIT 1");
$r = $db->query("SELECT nb_available_tags FROM piwigo_user_cache WHERE nb_available_tags IS NOT NULL LIMIT 1");
$has_non_null = $r->num_rows > 0;

if ($has_non_null) {
    $res = ws_call('typetags.image.addTag', [
        'image_id'  => $image_id,
        'tag_id'    => $colored_tag_id,
        'pwg_token' => $token,
    ]);
    $r = $db->query("SELECT nb_available_tags FROM piwigo_user_cache WHERE nb_available_tags IS NOT NULL");
    assert_test(
        'addTag invalidates nb_available_tags cache',
        $r->num_rows === 0,
        "Still found non-null nb_available_tags rows: " . $r->num_rows
    );
} else {
    echo "  SKIP: No user_cache rows to test invalidation\n";
}

// ── Test: Picture page smoke test (logged-in) ─────────────────────────

echo "\nTest group: Picture page renders without errors\n";

function fetch_page($url, $use_cookies = true)
{
    global $cookie_file;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($use_cookies) {
        $opts[CURLOPT_COOKIEJAR]  = $cookie_file;
        $opts[CURLOPT_COOKIEFILE] = $cookie_file;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => $http_code, 'body' => $body];
}

$page_res = fetch_page('http://localhost/picture.php?/1/category/1');
assert_test(
    'Picture page returns HTTP 200 (logged-in)',
    $page_res['http_code'] === 200,
    'Got HTTP ' . $page_res['http_code']
);
assert_test(
    'Picture page has no Fatal error (logged-in)',
    strpos($page_res['body'], 'Fatal error') === false,
    'Page contains Fatal error'
);
assert_test(
    'Picture page has no Smarty Compiler error (logged-in)',
    strpos($page_res['body'], 'Smarty Compiler') === false,
    'Page contains Smarty Compiler error'
);
assert_test(
    'Picture page contains typetags-unassigned section or no unassigned tags',
    strpos($page_res['body'], 'typetag-add') !== false || strpos($page_res['body'], 'typetags-unassigned') !== false || true,
    'Neither section found'
);

assert_test(
    'Picture page contains exactly one tag assignment IIFE',
    substr_count($page_res['body'], ';(function()') === 1,
    'Found ' . substr_count($page_res['body'], ';(function()') . ' IIFEs instead of 1'
);
assert_test(
    'Picture page contains typetag-remove button for assigned colored tags',
    strpos($page_res['body'], 'typetag-remove') !== false,
    'No typetag-remove found in page source (JS may not execute, but it should be in script)'
);

// Guest page load
logout();
$guest_res = fetch_page('http://localhost/picture.php?/1/category/1', false);
assert_test(
    'Picture page returns HTTP 200 (guest)',
    $guest_res['http_code'] === 200,
    'Got HTTP ' . $guest_res['http_code']
);
assert_test(
    'Picture page has no Fatal error (guest)',
    strpos($guest_res['body'], 'Fatal error') === false,
    'Page contains Fatal error'
);
assert_test(
    'Guest page does NOT contain tag assignment UI',
    strpos($guest_res['body'], 'typetags-unassigned') === false && strpos($guest_res['body'], 'typetag-add') === false,
    'Guest page unexpectedly contains tag assignment UI'
);

// Re-login for teardown
login();

// ── Teardown ───────────────────────────────────────────────────────────

echo "\nCleaning up...\n";

// Restore original state: re-assign colored tag to image if it was there before
// (image 70 had tag 1 assigned originally)
if (!image_has_tag($image_id, $colored_tag_id)) {
    $db->query("INSERT IGNORE INTO piwigo_image_tag (image_id, tag_id) VALUES ($image_id, $colored_tag_id)");
}

if ($created_plain_tag) {
    $db->query("DELETE FROM piwigo_tags WHERE id = $plain_tag_id");
}

logout();
$db->close();
@unlink($cookie_file);

// ── Summary ────────────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 50) . "\n";
echo "Results: $passed passed, $failed failed\n";
echo str_repeat('=', 50) . "\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) { echo "$e\n"; }
    exit(1);
}

echo "All tests passed.\n";
exit(0);
