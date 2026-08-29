<?php
/**
 * The test accounts the suites log in as, and the one place that creates them.
 *
 * Tests never use a human's real login: a suite that needs a webmaster gets a
 * dedicated account with a generated password, so nobody has to hand over their
 * own credentials and revoking test access is one script run.
 *
 * Roles, and why each exists:
 *   webmaster - pwg.plugins.performAction, which PluginActivationTest drives
 *   normal    - the permission model decision 0005 records: tag assignment is
 *               open to every logged-in user, and only an authenticated
 *               non-admin passing the gate can show that
 *
 * The `guest` account ships with Piwigo and is not created here.
 */
class TestUsers
{
    /** username => Piwigo status */
    public const ROLES = array(
        'typetags_webmaster' => 'webmaster',
        'typetags_normal'    => 'normal',
        );

    /** Written by create-test-users.php, read by Config, git-ignored under local/. */
    public const ENV_FILE = 'local/config/typetags-test.env';

    /** Environment variable pair for a role, as (username var, password var). */
    public static function envVars(string $role): array
    {
        $suffix = strtoupper(str_replace('typetags_', '', $role));
        return array(
            'TYPETAGS_TEST_' . $suffix . '_USERNAME',
            'TYPETAGS_TEST_' . $suffix . '_PASSWORD',
            );
    }
}
