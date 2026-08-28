<?php
/**
 * Environment-driven test configuration. No hardcoded credentials — every
 * value is either a safe, documented DDEV default (host/DB) or must come
 * from the environment (login credentials), failing fast with a message
 * naming the missing piece rather than silently trying a guessed value.
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

    public static function username(): string
    {
        return self::required('TYPETAGS_TEST_USERNAME');
    }

    public static function password(): string
    {
        return self::required('TYPETAGS_TEST_PASSWORD');
    }

    private static function required(string $envVar): string
    {
        $value = getenv($envVar);
        if ($value === false || $value === '')
        {
            throw new RuntimeException(
                "Missing required environment variable $envVar. " .
                "Set it to a test user's login on this DDEV install before running the integration suite."
            );
        }
        return $value;
    }
}
