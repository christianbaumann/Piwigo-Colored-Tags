<?php
/**
 * Environment-driven test configuration. No hardcoded credentials — every
 * value is either a safe, documented DDEV default (host/DB) or must come
 * from the environment (login credentials), failing fast with a message
 * naming the missing piece rather than silently trying a guessed value.
 *
 * The credentials belong to accounts this suite creates for itself
 * (TestUsers, create-test-users.php); no human's login is ever used. The
 * variable names are read out of TestUsers rather than typed twice.
 */
class Config
{
    public static function baseUrl(): string
    {
        return getenv('TYPETAGS_TEST_BASE_URL') ?: 'http://localhost';
    }

    public static function dbHost(): string
    {
        return getenv('TYPETAGS_TEST_DB_HOST') ?: 'db';
    }

    public static function dbUser(): string
    {
        return getenv('TYPETAGS_TEST_DB_USER') ?: 'db';
    }

    public static function dbPassword(): string
    {
        return getenv('TYPETAGS_TEST_DB_PASSWORD') ?: 'db';
    }

    public static function dbName(): string
    {
        return getenv('TYPETAGS_TEST_DB_NAME') ?: 'db';
    }

    /** The webmaster account. Named for its role, not for "the" test user. */
    public static function username(): string
    {
        return self::required(TestUsers::envVars('typetags_webmaster')[0]);
    }

    public static function password(): string
    {
        return self::required(TestUsers::envVars('typetags_webmaster')[1]);
    }

    /**
     * The authenticated non-admin.
     *
     * An admin gate is only proven by a non-admin meeting it, and decision 0005
     * records the opposite claim for tag assignment - that every logged-in user
     * may do it. Neither is testable with one account.
     */
    public static function normalUsername(): string
    {
        return self::required(TestUsers::envVars('typetags_normal')[0]);
    }

    public static function normalPassword(): string
    {
        return self::required(TestUsers::envVars('typetags_normal')[1]);
    }

    private static function required(string $envVar): string
    {
        $value = getenv($envVar);
        if ($value === false || $value === '')
        {
            throw new RuntimeException(
                "Missing required environment variable $envVar. " .
                'Run `ddev exec php plugins/typetags/tests/Support/create-test-users.php` to create ' .
                'the test accounts, then source ' . TestUsers::ENV_FILE . ' before running the suite.'
            );
        }
        return $value;
    }
}
