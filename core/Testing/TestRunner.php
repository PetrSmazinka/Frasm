<?php

declare(strict_types=1);

namespace Core\Testing;

use Core\Console\Output;
use Core\Exceptions\AssertionFailedException;
use Core\Exceptions\SkippedTestException;
use ErrorException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * @file TestRunner.php
 * @brief Finds and runs the application tests.
 */

/**
 * @class TestRunner
 * @brief Discovers TestCase classes below a directory and runs their test methods.
 *
 * Classes live in files named after them (…Test.php) below app/Tests, with the namespace following
 * the directories (app/Tests/Blog/MarkdownTest.php → App\Tests\Blog\MarkdownTest). A filter selects
 * tests by a part of "Blog\MarkdownTest::testLinks" (case-insensitive; "blog" or "blog/markdown"
 * work as well). PHP warnings and notices fail the test, output printed by a test is reported.
 */
final class TestRunner
{
    /**
     * @var array{passed: int, failed: int, errors: int, skipped: int, assertions: int} Totals of the run.
     */
    private array $totals = ['passed' => 0, 'failed' => 0, 'errors' => 0, 'skipped' => 0, 'assertions' => 0];

    /**
     * @var list<array{test: string, kind: string, message: string, location: string}> Failures and errors.
     */
    private array $problems = [];

    /**
     * @brief TestRunner constructor.
     *
     * @param Output $output Console output.
     */
    public function __construct(private readonly Output $output)
    {
    }

    /**
     * @brief Finds the test classes below a directory.
     *
     * @param string $directory Tests directory.
     * @param string $namespace Namespace mapped to the directory.
     * @return list<class-string<TestCase>> Sorted by name.
     */
    public function discover(string $directory, string $namespace = 'App\\Tests\\'): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            return [];
        }

        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = rtrim($namespace, '\\') . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (!class_exists($class)) {
                require_once $file->getPathname();
            }
            if (class_exists($class) && is_subclass_of($class, TestCase::class) && !(new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        sort($classes, SORT_STRING);
        return $classes;
    }

    /**
     * @brief Runs the tests of the classes.
     *
     * @param list<class-string<TestCase>> $classes Test classes.
     * @param string|null $filter Part of "Directory\ClassTest::testMethod" selecting tests.
     * @param bool $stopOnFailure Stop at the first failure or error.
     * @param string $namespace Namespace stripped from the displayed names.
     * @return bool True when no test failed or errored.
     */
    public function run(array $classes, ?string $filter = null, bool $stopOnFailure = false, string $namespace = 'App\\Tests\\'): bool
    {
        $needle = $filter === null || $filter === '' ? null : strtolower(str_replace('/', '\\', $filter));
        $started = microtime(true);

        foreach ($classes as $class) {
            $name = str_starts_with($class, $namespace) ? substr($class, strlen($namespace)) : $class;
            $methods = array_values(array_filter(
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
                fn(ReflectionMethod $method): bool => str_starts_with($method->getName(), 'test') && !$method->isStatic()
                    && ($needle === null || str_contains(strtolower("{$name}::{$method->getName()}"), $needle))
            ));
            if ($methods === []) {
                continue;
            }

            $this->output->line($this->output->style($name, 'bold'));
            try {
                $class::setUpBeforeClass();
            } catch (Throwable $e) {
                $this->totals['errors']++;
                $this->record("{$name}::setUpBeforeClass", 'error', $e, $class);
                $this->output->line('  ' . $this->output->style('✖', 'red') . ' setUpBeforeClass: ' . $e->getMessage());
                if ($stopOnFailure) {
                    break;
                }
                continue;
            }

            foreach ($methods as $method) {
                if (!$this->runTest($class, $name, $method->getName()) && $stopOnFailure) {
                    break 2;
                }
            }

            try {
                $class::tearDownAfterClass();
            } catch (Throwable $e) {
                $this->totals['errors']++;
                $this->record("{$name}::tearDownAfterClass", 'error', $e, $class);
            }
        }

        $this->summary(microtime(true) - $started);

        return $this->totals['failed'] === 0 && $this->totals['errors'] === 0;
    }

    /**
     * @brief Returns the totals of the run.
     *
     * @return array{passed: int, failed: int, errors: int, skipped: int, assertions: int}
     */
    public function totals(): array
    {
        return $this->totals;
    }

    /**
     * @brief Runs one test on a fresh instance.
     *
     * @param class-string<TestCase> $class Test class.
     * @param string $name Displayed class name.
     * @param string $method Test method.
     * @return bool False when the test failed or errored.
     */
    private function runTest(string $class, string $name, string $method): bool
    {
        $start = microtime(true);
        $label = preg_replace('/^test_?/', '', $method);

        // Warnings and notices fail the test; output of the test is caught (it would also break sessions)
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        ob_start();

        $kind = 'passed';
        $problem = null;
        try {
            $this->totals['assertions'] += (new $class())->runTest($method);
        } catch (SkippedTestException $e) {
            $kind = 'skipped';
            $problem = $e;
        } catch (AssertionFailedException $e) {
            $kind = 'failed';
            $problem = $e;
        } catch (Throwable $e) {
            $kind = 'errors';
            $problem = $e;
        } finally {
            $printed = (string)ob_get_clean();
            restore_error_handler();
            $this->resetSession();
        }

        $this->totals[$kind]++;
        $time = sprintf(' %s', $this->output->style(round((microtime(true) - $start) * 1000) . ' ms', 'dim'));

        match ($kind) {
            'passed'  => $this->output->line('  ' . $this->output->style('✔', 'green') . " {$label}{$time}"),
            'skipped' => $this->output->line('  ' . $this->output->style('–', 'yellow') . " {$label} " . $this->output->style('(' . $problem?->getMessage() . ')', 'dim')),
            default   => $this->output->line('  ' . $this->output->style('✖', 'red') . " {$label}{$time}"),
        };
        if ($printed !== '' && $kind === 'passed') {
            $this->output->line('    ' . $this->output->style('printed ' . strlen($printed) . ' bytes of output', 'yellow'));
        }

        if ($problem !== null && $kind !== 'skipped') {
            $this->record("{$name}::{$method}", $kind === 'failed' ? 'failure' : 'error', $problem, $class);
            return false;
        }

        return true;
    }

    /**
     * @brief Remembers a failure or error with the line of the test where it happened.
     *
     * @param string $test Test name.
     * @param string $kind 'failure' or 'error'.
     * @param Throwable $e Exception.
     * @param class-string $class Test class.
     * @return void
     */
    private function record(string $test, string $kind, Throwable $e, string $class): void
    {
        $testFile = (string)(new ReflectionClass($class))->getFileName();
        $location = basename($e->getFile()) . ':' . $e->getLine();
        foreach ($e->getTrace() as $frame) {
            if (($frame['file'] ?? null) === $testFile) {
                $location = basename($testFile) . ':' . $frame['line'];
                break;
            }
        }
        if ($e->getFile() === $testFile) {
            $location = basename($testFile) . ':' . $e->getLine();
        }

        $message = $kind === 'error' ? $e::class . ': ' . $e->getMessage() : $e->getMessage();
        if ($kind === 'error' && $e->getFile() !== $testFile) {
            $message .= ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }

        $this->problems[] = ['test' => $test, 'kind' => $kind, 'message' => $message, 'location' => $location];
    }

    /**
     * @brief Prints the failures and the totals.
     *
     * @param float $seconds Duration of the run.
     * @return void
     */
    private function summary(float $seconds): void
    {
        foreach ($this->problems as $index => $problem) {
            $this->output->line();
            $this->output->line($this->output->style(($index + 1) . ') ' . $problem['test'], 'red') . ' ' . $this->output->style("[{$problem['location']}]", 'dim'));
            $this->output->line('   ' . ($problem['kind'] === 'error' ? 'Error: ' : '') . $problem['message']);
        }

        $t = $this->totals;
        $line = sprintf(
            '%d passed, %d failed, %d errors, %d skipped, %d assertions (%.1f s)',
            $t['passed'], $t['failed'], $t['errors'], $t['skipped'], $t['assertions'], $seconds
        );
        $this->output->line();
        $t['failed'] + $t['errors'] === 0 ? $this->output->success($line) : $this->output->error($line);
    }

    /**
     * @brief Ends the session of a test, so the next one starts signed out.
     *
     * @return void
     */
    private function resetSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        $_SESSION = [];
    }
}
