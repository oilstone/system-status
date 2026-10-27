<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Monitors;

use Illuminate\Contracts\Filesystem\Factory;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;

/**
 * Writes, reads back and deletes a uniquely named file.
 *
 * Options:
 * - disk: name from config/filesystems.php (default disk when omitted)
 * - directory (default "system-status")
 */
class Disk extends Monitor
{
    protected string $name = 'Disk';

    public function __construct(array $config, protected Factory $filesystem)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return $this->config['name'] ?? sprintf('Disk (%s)', $this->config['disk'] ?? 'default');
    }

    public function check(Probe $probe): void
    {
        $disk = $this->filesystem->disk($this->config['disk'] ?? null);
        $path = trim($this->config['directory'] ?? 'system-status', '/') . '/' . bin2hex(random_bytes(8)) . '.txt';
        $contents = bin2hex(random_bytes(8));

        if (! $disk->put($path, $contents)) {
            $this->fail(sprintf('Unable to write %s', $path));
        }

        try {
            if ($disk->get($path) !== $contents) {
                $this->fail(sprintf('Read back different contents from %s', $path));
            }
        } finally {
            $disk->delete($path);
        }
    }
}
