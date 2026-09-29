<?php

declare(strict_types=1);

namespace Core\Testing;

use Core\Container\Container;
use Core\DB\DB;
use Core\Exceptions\AssertionFailedException;
use Core\Exceptions\SkippedTestException;
use Core\Http\Kernel;
use Throwable;

/**
 * @file TestCase.php
 * @brief Base class of application tests (classes named …Test in app/Tests and its subdirectories).
 */

/**
 * @class TestCase
 * @brief Assertions, lifecycle hooks, test database helpers and an in-process HTTP client.
 *
 * Every public method whose name starts with "test" is a test; each runs on a fresh instance,
 * between setUp() and tearDown(). setUpBeforeClass() / tearDownAfterClass() run once per class.
 *
 *     final class MoneyTest extends TestCase
 *     {
 *         public function testParsesCzechNotation(): void
 *         {
 *             $this->assertSame(123450, Money::parse('1 234,50'));
 *         }
 *     }
 *
 * Tests run against the test databases configured under `testing` (see TestRunner); connections
 * without one are disabled, so a test can never touch real data.
 */
abstract class TestCase
{
    /**
     * @var int Assertions made by the current test.
     */
    private int $assertions = 0;

    /**
     * @brief Runs once before the first test of the class.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
    }

    /**
     * @brief Runs once after the last test of the class.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
    }

    /**
     * @brief Runs before every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
    }

    /**
     * @brief Runs after every test (also when it failed).
     *
     * @return void
     */
    protected function tearDown(): void
    {
    }

    /**
     * @brief Runs one test method with its hooks (used by TestRunner).
     *
     * @param string $method Test method name.
     * @return int Number of assertions made.
     * @throws Throwable Whatever the test or its hooks throw.
     */
    final public function runTest(string $method): int
    {
        $this->assertions = 0;
        $this->setUp();
        try {
            $this->{$method}();
        } finally {
            $this->tearDown();
        }

        return $this->assertions;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @brief Returns an in-process HTTP client for the application.
     *
     * @param string $host Host name of the requests (selects the domain of `app.domains`).
     * @param bool $https Send requests as HTTPS.
     * @return TestClient
     */
    protected function http(string $host = 'localhost', bool $https = true): TestClient
    {
        return new TestClient(Container::getInstance()->get(Kernel::class), $host, $https, function (): void {
            $this->assertions++;
        });
    }

    /**
     * @brief Returns a database connection (the test database).
     *
     * @param string|null $connection Connection name, or null for the default one.
     * @return DB
     */
    protected function db(?string $connection = null): DB
    {
        return DB::connection($connection);
    }

    /**
     * @brief Creates a user in the test database (frasm_users) and returns its ID.
     *
     * Sign it in with $this->http()->actingAs($id); its roles come from the database, as the
     * framework reloads them on every request.
     *
     * @param list<string> $roles Roles (permissions).
     * @param string|null $username Username (default: a unique one).
     * @param string $password Password (hashed with a cheap bcrypt cost to keep tests fast).
     * @return int User ID.
     */
    protected function createUser(array $roles = [], ?string $username = null, string $password = 'test-password'): int
    {
        $username ??= 'user' . bin2hex(random_bytes(4));

        return (int)DB::connection()->insert('frasm_users', [
            'name'          => ucfirst($username),
            'email'         => "{$username}@example.test",
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'permissions'   => implode(',', $roles),
        ]);
    }

    /**
     * @brief Empties every table of connections (the migration records stay).
     *
     * @param string ...$connections Connection names (none = the default connection).
     * @return void
     */
    protected function truncate(string ...$connections): void
    {
        foreach ($connections === [] ? [null] : $connections as $connection) {
            $db = DB::connection($connection);
            $tables = array_column($db->select(
                "SELECT `TABLE_NAME` FROM information_schema.`TABLES`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_TYPE` = 'BASE TABLE' AND `TABLE_NAME` <> 'frasm_migrations'"
            ), 'TABLE_NAME');

            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $db->query('TRUNCATE TABLE ' . $db->quoteIdentifier((string)$table));
                }
            } finally {
                $db->query('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }

    /**
     * @brief Stops the test as skipped.
     *
     * @param string $reason Why the test does not run.
     * @return never
     * @throws SkippedTestException
     */
    protected function skip(string $reason): never
    {
        throw new SkippedTestException($reason);
    }

    // --------------------------------------------------------------- assertions

    /**
     * @brief Fails the test.
     *
     * @param string $message Failure message.
     * @return never
     * @throws AssertionFailedException
     */
    protected function fail(string $message): never
    {
        throw new AssertionFailedException($message);
    }

    /**
     * @brief Asserts that a condition holds.
     *
     * @param bool $condition Condition.
     * @param string $message Failure message.
     * @return void
     */
    protected function assertTrue(bool $condition, string $message = 'Failed asserting that the condition is true.'): void
    {
        $this->assertions++;
        if (!$condition) {
            $this->fail($message);
        }
    }

    /**
     * @brief Asserts that a condition does not hold.
     *
     * @param bool $condition Condition.
     * @param string $message Failure message.
     * @return void
     */
    protected function assertFalse(bool $condition, string $message = 'Failed asserting that the condition is false.'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    /**
     * @brief Asserts identity (===).
     *
     * @param mixed $expected Expected value.
     * @param mixed $actual Actual value.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertTrue($expected === $actual, self::prefix($message) . 'Expected ' . self::export($expected) . ', got ' . self::export($actual) . '.');
    }

    /**
     * @brief Asserts that two values are not identical.
     *
     * @param mixed $unexpected Value that must not appear.
     * @param mixed $actual Actual value.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        $this->assertTrue($unexpected !== $actual, self::prefix($message) . 'Did not expect ' . self::export($actual) . '.');
    }

    /**
     * @brief Asserts equality (==), e.g. of arrays regardless of key order.
     *
     * @param mixed $expected Expected value.
     * @param mixed $actual Actual value.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertTrue($expected == $actual, self::prefix($message) . 'Expected ' . self::export($expected) . ', got ' . self::export($actual) . '.');
    }

    /**
     * @brief Asserts that a value is null.
     *
     * @param mixed $actual Value.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    /**
     * @brief Asserts that a value is not null.
     *
     * @param mixed $actual Value.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->assertTrue($actual !== null, self::prefix($message) . 'Expected a value, got null.');
    }

    /**
     * @brief Asserts the number of elements.
     *
     * @param int $expected Expected count.
     * @param \Countable|array<mixed> $actual Array or countable.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertCount(int $expected, \Countable|array $actual, string $message = ''): void
    {
        $this->assertTrue(count($actual) === $expected, self::prefix($message) . "Expected {$expected} elements, got " . count($actual) . '.');
    }

    /**
     * @brief Asserts that an array contains a value (strictly).
     *
     * @param mixed $needle Value.
     * @param array<mixed> $haystack Array.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertTrue(in_array($needle, $haystack, true), self::prefix($message) . 'Array does not contain ' . self::export($needle) . '.');
    }

    /**
     * @brief Asserts that a string contains a substring.
     *
     * @param string $needle Substring.
     * @param string $haystack String.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(str_contains($haystack, $needle), self::prefix($message) . 'Failed asserting that ' . self::export(self::excerpt($haystack)) . ' contains ' . self::export($needle) . '.');
    }

    /**
     * @brief Asserts that a string does not contain a substring.
     *
     * @param string $needle Substring.
     * @param string $haystack String.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(!str_contains($haystack, $needle), self::prefix($message) . 'Failed asserting that the string does not contain ' . self::export($needle) . '.');
    }

    /**
     * @brief Asserts that a string matches a regular expression.
     *
     * @param string $pattern PCRE pattern.
     * @param string $subject String.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertMatchesRegex(string $pattern, string $subject, string $message = ''): void
    {
        $this->assertTrue(preg_match($pattern, $subject) === 1, self::prefix($message) . 'Failed asserting that ' . self::export(self::excerpt($subject)) . " matches {$pattern}.");
    }

    /**
     * @brief Asserts that a value is an instance of a class.
     *
     * @param class-string $class Class or interface.
     * @param mixed $actual Value.
     * @param string $message Failure message prefix.
     * @return void
     */
    protected function assertInstanceOf(string $class, mixed $actual, string $message = ''): void
    {
        $this->assertTrue($actual instanceof $class, self::prefix($message) . "Expected an instance of {$class}, got " . get_debug_type($actual) . '.');
    }

    /**
     * @brief Asserts that a callback throws.
     *
     * @param class-string<Throwable> $class Expected exception class (or a parent).
     * @param callable $callback Code expected to throw.
     * @param string|null $messageContains Part of the expected exception message.
     * @return Throwable The caught exception, for further assertions.
     */
    protected function assertThrows(string $class, callable $callback, ?string $messageContains = null): Throwable
    {
        $this->assertions++;
        try {
            $callback();
        } catch (AssertionFailedException | SkippedTestException $e) {
            throw $e;
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                $this->fail("Expected {$class}, got " . $e::class . ': ' . $e->getMessage());
            }
            if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
                $this->fail("Expected the message of {$class} to contain " . self::export($messageContains) . ', got ' . self::export($e->getMessage()) . '.');
            }

            return $e;
        }

        $this->fail("Expected {$class} to be thrown.");
    }

    /**
     * @brief Formats a value for failure messages.
     *
     * @param mixed $value Value.
     * @return string
     */
    protected static function export(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            return is_string($json) ? $json : get_debug_type($value);
        }

        return var_export($value, true);
    }

    /**
     * @brief Shortens long strings in failure messages.
     *
     * @param string $text Text.
     * @return string
     */
    private static function excerpt(string $text): string
    {
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '…' : $text;
    }

    /**
     * @brief Formats a custom message prefix.
     *
     * @param string $message Message.
     * @return string
     */
    private static function prefix(string $message): string
    {
        return $message === '' ? '' : rtrim($message) . ' ';
    }
}
