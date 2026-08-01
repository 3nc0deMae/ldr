<?php
/**
 * LDB-FRAS Test Framework Bootstrap (Phase 19)
 * Lightweight test runner - no external dependencies required
 *
 * Usage:
 *   require_once __DIR__ . '/bootstrap.php';
 *   $t = new TestSuite('My Test Group');
 *   $t->assert(true, 'Something should be true');
 *   $t->assertEqual(1, 1, 'One equals one');
 *   $t->results();
 */

// Prevent session start during tests
define('TESTING', true);

// Error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Timezone
date_default_timezone_set('Asia/Manila');

// Define project root (two levels up from tests/)
define('TEST_ROOT', dirname(__DIR__));

// Include core files without starting session
require_once TEST_ROOT . '/vendor/autoload.php';
require_once TEST_ROOT . '/includes/db.php';
require_once TEST_ROOT . '/includes/functions.php';

// Python Face Recognition API constants (mirrors config.php for test contexts)
if (!defined('PYTHON_API_URL')) define('PYTHON_API_URL', 'http://localhost:5000');
if (!defined('PYTHON_API_KEY')) define('PYTHON_API_KEY', 'ldb_fras_api_key_2026');

// Include security.php selectively (avoid session-dependent functions)
// Some functions like validateRequired, validateLength etc. don't need session
require_once TEST_ROOT . '/includes/security.php';

/**
 * Minimal test suite class
 */
class TestSuite {
    private $name;
    private $tests    = [];
    private $passed   = 0;
    private $failed   = 0;
    private $errors   = [];
    private $startTime;

    public function __construct($name = 'Test Suite') {
        $this->name      = $name;
        $this->startTime = microtime(true);
    }

    /**
     * Assert a condition is true
     */
    public function assert($condition, $message = '') {
        $this->runTest($message, $condition);
    }

    /**
     * Assert two values are equal
     */
    public function assertEqual($expected, $actual, $message = '') {
        $msg = $message ?: "Expected '$expected', got '$actual'";
        $this->runTest($msg, $expected === $actual);
    }

    /**
     * Assert two values are not equal
     */
    public function assertNotEqual($expected, $actual, $message = '') {
        $msg = $message ?: "Expected NOT '$expected'";
        $this->runTest($msg, $expected !== $actual);
    }

    /**
     * Assert value is not empty
     */
    public function assertNotEmpty($value, $message = '') {
        $this->runTest($message ?: 'Value should not be empty', !empty($value));
    }

    /**
     * Assert value is empty
     */
    public function assertEmpty($value, $message = '') {
        $this->runTest($message ?: 'Value should be empty', empty($value));
    }

    /**
     * Assert value is true
     */
    public function assertTrue($value, $message = '') {
        $this->runTest($message ?: 'Value should be true', $value === true);
    }

    /**
     * Assert value is false
     */
    public function assertFalse($value, $message = '') {
        $this->runTest($message ?: 'Value should be false', $value === false);
    }

    /**
     * Assert value is null
     */
    public function assertNull($value, $message = '') {
        $this->runTest($message ?: 'Value should be null', $value === null);
    }

    /**
     * Assert value is not null
     */
    public function assertNotNull($value, $message = '') {
        $this->runTest($message ?: 'Value should not be null', $value !== null);
    }

    /**
     * Assert string contains substring
     */
    public function assertContains($needle, $haystack, $message = '') {
        $msg = $message ?: "String should contain '$needle'";
        $this->runTest($msg, strpos($haystack, $needle) !== false);
    }

    /**
     * Assert array has key
     */
    public function assertArrayHasKey($key, $array, $message = '') {
        $msg = $message ?: "Array should have key '$key'";
        $this->runTest($msg, is_array($array) && array_key_exists($key, $array));
    }

    /**
     * Assert integer is greater than
     */
    public function assertGreaterThan($expected, $actual, $message = '') {
        $msg = $message ?: "$actual should be greater than $expected";
        $this->runTest($msg, $actual > $expected);
    }

    /**
     * Assert value is instance of class
     */
    public function assertInstanceOf($class, $object, $message = '') {
        $msg = $message ?: 'Object should be instance of ' . $class;
        $this->runTest($msg, $object instanceof $class);
    }

    /**
     * Assert value is an array
     */
    public function assertIsArray($value, $message = '') {
        $this->runTest($message ?: 'Value should be an array', is_array($value));
    }

    /**
     * Assert value is a boolean
     */
    public function assertIsBool($value, $message = '') {
        $this->runTest($message ?: 'Value should be a boolean', is_bool($value));
    }

    /**
     * Run a single test and record result
     */
    private function runTest($message, $condition) {
        if ($condition) {
            $this->passed++;
            $this->tests[] = ['status' => 'pass', 'message' => $message];
        } else {
            $this->failed++;
            $this->tests[] = ['status' => 'fail', 'message' => $message];
            $this->errors[] = $message;
        }
    }

    /**
     * Get test results as array
     */
    public function getResults() {
        $elapsed = round((microtime(true) - $this->startTime) * 1000, 2);
        return [
            'name'    => $this->name,
            'total'   => $this->passed + $this->failed,
            'passed'  => $this->passed,
            'failed'  => $this->failed,
            'errors'  => $this->errors,
            'tests'   => $this->tests,
            'elapsed' => $elapsed,
            'success' => $this->failed === 0
        ];
    }

    /**
     * Print results (for CLI)
     */
    public function printResults() {
        $r = $this->getResults();
        echo "\n=== {$r['name']} ===\n";
        foreach ($r['tests'] as $t) {
            $icon = $t['status'] === 'pass' ? '  ✓' : '  ✗';
            echo "$icon {$t['message']}\n";
        }
        echo "\nTotal: {$r['total']} | Pass: {$r['passed']} | Fail: {$r['failed']} | Time: {$r['elapsed']}ms\n";
    }
}

/**
 * Get a test database connection (safe for testing)
 * @return PDO|null
 */
function getTestDB() {
    try {
        $database = new Database();
        return $database->getConnection();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Run a test file and collect results
 * @param string $file  Full path to test file
 * @return array
 */
function runTestFile($file) {
    if (!file_exists($file)) {
        return ['name' => basename($file), 'total' => 0, 'passed' => 0, 'failed' => 0,
                'errors' => ['File not found: ' . $file], 'tests' => [], 'elapsed' => 0, 'success' => false];
    }
    try {
        $result = include $file;
        if ($result instanceof TestSuite) {
            return $result->getResults();
        }
        return $result ?: ['name' => basename($file), 'total' => 0, 'passed' => 0, 'failed' => 0,
                           'errors' => [], 'tests' => [], 'elapsed' => 0, 'success' => true];
    } catch (Throwable $e) {
        return ['name' => basename($file), 'total' => 1, 'passed' => 0, 'failed' => 1,
                'errors' => [$e->getMessage()], 'tests' => [['status' => 'fail', 'message' => $e->getMessage()]],
                'elapsed' => 0, 'success' => false];
    }
}
