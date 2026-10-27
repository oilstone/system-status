<?php

namespace Oilstone\SystemStatus\Tests\Unit\Monitors;

use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Monitors\File;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'system-status-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_it_checks_files_and_directories_exist(): void
    {
        $this->assertSame(Rating::Optimal, $this->check(['path' => $this->path])->rating);
        $this->assertSame(Rating::Optimal, $this->check(['path' => sys_get_temp_dir()])->rating);

        $missing = $this->check(['path' => $this->path . '.missing']);

        $this->assertSame(Rating::Failed, $missing->rating);
        $this->assertSame($this->path . '.missing is not present', $missing->errorMessage());
    }

    public function test_it_checks_permissions(): void
    {
        $this->assertSame(Rating::Optimal, $this->check(['path' => $this->path, 'checks' => ['exists', 'readable', 'writable']])->rating);

        chmod($this->path, 0444);

        if (is_writable($this->path)) {
            $this->markTestSkipped('Permissions are not enforced for this user');
        }

        $this->assertSame($this->path . ' is not writable', $this->check(['path' => $this->path, 'checks' => 'writable'])->errorMessage());
    }

    public function test_it_checks_the_age_of_a_file(): void
    {
        touch($this->path, time() - 120);

        $fresh = $this->check(['path' => $this->path, 'max_age' => 300]);

        $this->assertSame(Rating::Optimal, $fresh->rating);
        $this->assertGreaterThanOrEqual(120, $fresh->meta['age_seconds']);

        $stale = $this->check(['path' => $this->path, 'max_age' => 60]);

        $this->assertSame(Rating::Failed, $stale->rating);
        $this->assertMatchesRegularExpression('/was last modified 12\d+s ago \(limit 60s\)$/', $stale->errorMessage());
    }

    public function test_unknown_checks_are_rejected_when_built(): void
    {
        $this->expectException(InvalidMonitorConfiguration::class);
        $this->expectExceptionMessage('File is misconfigured: unknown checks executable');

        new File(['path' => $this->path, 'checks' => ['exists', 'executable']]);
    }

    protected function check(array $config): Result
    {
        return (new SystemStatus())->add(['type' => 'file'] + $config)->check()->results()[0];
    }
}
