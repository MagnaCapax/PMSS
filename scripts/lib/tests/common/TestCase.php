<?php
namespace PMSS\Tests;

class SkipTest extends \Exception {}

abstract class TestCase
{
    private array $results = [];

    public function run(): array
    {
        $methods = array_filter(get_class_methods($this), static function ($method) {
            return str_starts_with($method, 'test');
        });
        foreach ($methods as $method) {
            try {
                $this->$method();
                $this->results[] = [true, $method, null];
            } catch (SkipTest $e) {
                $this->results[] = ['skip', $method, $e->getMessage()];
            } catch (\AssertionError $e) {
                $this->results[] = [false, $method, $e->getMessage()];
            } catch (\Throwable $e) {
                $this->results[] = [false, $method, $e->getMessage()];
            }
        }
        return $this->results;
    }

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \AssertionError($message !== '' ? $message : 'Assertion failed: expected true');
        }
    }

    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            $msg = $message !== '' ? $message : sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
            throw new \AssertionError($msg);
        }
    }

    protected function assertMatches(string $pattern, string $value, string $message = ''): void
    {
        if (!preg_match($pattern, $value)) {
            $msg = $message !== '' ? $message : sprintf('Value %s does not match pattern %s', $value, $pattern);
            throw new \AssertionError($msg);
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (strpos($haystack, $needle) === false) {
            $msg = $message !== '' ? $message : sprintf('Expected string to contain %s, but it did not', var_export($needle, true));
            throw new \AssertionError($msg);
        }
    }

    protected function isSandbox(): bool
    {
        if (getenv('PMSS_SANDBOX') === '1') return true;
        // Not root or no systemd bus → assume sandbox/CI
        $notRoot = function_exists('posix_geteuid') ? (posix_geteuid() !== 0) : true;
        $noSystemd = !is_dir('/run/systemd/system');
        return $notRoot || $noSystemd;
    }

    protected function skipIfSandbox(string $reason = 'sandboxed environment'): void
    {
        if ($this->isSandbox()) {
            throw new SkipTest($reason);
        }
    }
}
