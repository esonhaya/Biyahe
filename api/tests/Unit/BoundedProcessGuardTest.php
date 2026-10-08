<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

class BoundedProcessGuardTest extends TestCase
{
    public function test_available_memory_gate_rejects_before_worker_start(): void
    {
        $fixture = $this->fixture(700000);
        $marker = $fixture['root'].'/worker-started';

        try {
            $process = $this->runGuard($fixture, [
                'BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB' => '800000',
            ], ['bash', '-c', 'touch "$1"', 'worker', $marker]);

            $this->assertSame(4, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('command_not_started=yes', $process->getOutput());
            $this->assertFileDoesNotExist($marker);
            $this->assertFileDoesNotExist($fixture['log'].'.runner');
        } finally {
            $this->removeFixture($fixture['root']);
        }
    }

    public function test_low_swap_does_not_block_a_bounded_worker_and_rss_is_recorded(): void
    {
        $fixture = $this->fixture(700000);
        $marker = $fixture['root'].'/worker-complete';

        try {
            $process = $this->runGuard($fixture, [
                'BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB' => '524288',
                'BIYAHE_RESOURCE_MIN_DISKFREE_KB' => '524288',
                'BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB' => '65536',
                'BIYAHE_RESOURCE_WATCH_FILE' => $marker,
                'BIYAHE_RESOURCE_PROGRESS_FILE' => $marker,
            ], ['bash', '-c', 'printf complete > "$1"; sleep 1.4', 'worker', $marker]);

            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame('complete', file_get_contents($marker));
            $this->assertStringContainsString('swapfree_kb=0', $process->getOutput());
            $this->assertStringContainsString('cached_kb=1234', $process->getOutput());
            $this->assertStringContainsString('sreclaimable_kb=4321', $process->getOutput());
            $this->assertStringContainsString('sqlite_bytes=8', $process->getOutput());
            $this->assertStringContainsString('producer_consumer_progress=complete', $process->getOutput());
            $this->assertStringContainsString('process_tree_rss_kb=4000', $process->getOutput());
            $this->assertStringContainsString('operation=complete worker_exit=0 peak_rss_kb=4000', $process->getOutput());
            $this->assertFileDoesNotExist($fixture['log'].'.runner');
            $this->assertSame([], glob($fixture['root'].'/.resource-worker.*') ?: []);
        } finally {
            $this->removeFixture($fixture['root']);
        }
    }

    public function test_rss_breach_stops_only_the_named_descendant_and_worker_unwinds(): void
    {
        $fixture = $this->fixture(700000);
        $fifo = $fixture['root'].'/stream.fifo';
        $partial = $fixture['root'].'/partial-output';
        $worker = $fixture['root'].'/worker.sh';
        file_put_contents($worker, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
mkfifo "$FIXTURE_FIFO"
sleep 90 > "$FIXTURE_FIFO" &
producer=$!
printf '%s\n' "$producer" > "$FIXTURE_PID_FILE"
cat "$FIXTURE_FIFO" > "$FIXTURE_PARTIAL"
BASH);
        chmod($worker, 0700);

        try {
            $process = $this->runGuard($fixture, [
                'BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB' => '524288',
                'BIYAHE_RESOURCE_MIN_DISKFREE_KB' => '524288',
                'BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB' => '65536',
                'BIYAHE_RESOURCE_BREACH_SAMPLES' => '1',
                'FIXTURE_FIFO' => $fifo,
                'FIXTURE_PID_FILE' => $fixture['producerPid'],
                'FIXTURE_PARTIAL' => $partial,
            ], ['bash', $worker]);

            $this->assertSame(6, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('process_tree_rss_84000_over_65536', $process->getOutput());
            $this->assertStringContainsString('producer_stop=SIGTERM', $process->getOutput());
            $this->assertStringContainsString('operation=stopped_for_resource_budget', $process->getOutput());
            $this->assertFileExists($partial);
            $this->assertSame('', file_get_contents($partial));
            $this->assertFileDoesNotExist($fixture['log'].'.runner');
            $this->assertSame([], glob($fixture['root'].'/.resource-worker.*') ?: []);
        } finally {
            $this->removeFixture($fixture['root']);
        }
    }

    /** @return array{root: string, bin: string, log: string, meminfo: string, calls: string, runnerPid: string, producerPid: string, realPs: string} */
    private function fixture(int $availableMemory): array
    {
        $root = sys_get_temp_dir().'/biyahe-bounded-process-'.bin2hex(random_bytes(6));
        $bin = $root.'/bin';
        mkdir($bin, 0700, true);
        $log = $root.'/guard.log';
        $meminfo = $root.'/meminfo';
        $calls = $root.'/calls';
        $runnerPid = $log.'.runner';
        $producerPid = $root.'/producer.pid';
        file_put_contents($calls, '');
        file_put_contents($meminfo, "MemTotal: 1024000 kB\nMemAvailable: ".$availableMemory." kB\nCached: 1234 kB\nSReclaimable: 4321 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB\n");

        $realPs = $this->findExecutable('ps');
        $ps = <<<'BASH'
#!/usr/bin/env bash
has_all=0
has_pid=0
requested_pid=
for argument in "$@"; do
  [[ "$argument" == -e ]] && has_all=1
  if [[ "$has_pid" == 1 ]]; then requested_pid="$argument"; has_pid=0; fi
  [[ "$argument" == -p ]] && has_pid=1
done
if [[ "$has_all" == 1 ]]; then
  runner="$(cat "$FIXTURE_RUNNER_PID" 2>/dev/null || true)"
  producer="$(cat "$FIXTURE_PID_FILE" 2>/dev/null || true)"
  if [[ "$runner" =~ ^[0-9]+$ ]]; then
    printf '%s 1 4000\n' "$runner"
    if [[ "$producer" =~ ^[0-9]+$ ]]; then printf '%s %s 80000\n' "$producer" "$runner"; fi
  fi
  exit 0
fi
if [[ "$requested_pid" =~ ^[0-9]+$ ]]; then
  producer="$(cat "$FIXTURE_PID_FILE" 2>/dev/null || true)"
  if [[ "$requested_pid" == "$producer" ]]; then printf 'sleep 90\n'; exit 0; fi
fi
exec "$REAL_PS" "$@"
BASH;
        file_put_contents($bin.'/ps', $ps."\n");
        chmod($bin.'/ps', 0700);

        return compact('root', 'bin', 'log', 'meminfo', 'calls', 'runnerPid', 'producerPid', 'realPs');
    }

    /** @param array<string, string> $extraEnvironment @param list<string> $command */
    private function runGuard(array $fixture, array $extraEnvironment, array $command): Process
    {
        $script = dirname(__DIR__, 2).'/scripts/run-bounded-process.sh';
        $environment = array_merge([
            'PATH' => $fixture['bin'].':'.(getenv('PATH') ?: ''),
            'REAL_PS' => $fixture['realPs'],
            'FIXTURE_RUNNER_PID' => $fixture['runnerPid'],
            'FIXTURE_PID_FILE' => $fixture['producerPid'],
            'BIYAHE_RESOURCE_MEMINFO_PATH' => $fixture['meminfo'],
            'BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB' => '524288',
            'BIYAHE_RESOURCE_MIN_DISKFREE_KB' => '524288',
            'BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB' => '262144',
            'BIYAHE_RESOURCE_BREACH_SAMPLES' => '1',
            'BIYAHE_RESOURCE_POLL_SECONDS' => '1',
            'BIYAHE_RESOURCE_CANCEL_GRACE_SECONDS' => '5',
        ], $extraEnvironment);
        $arguments = [
            'bash', $script,
            '--operation', 'test-worker',
            '--disk-path', $fixture['root'],
            '--cancel-pid-file', $fixture['producerPid'],
            '--cancel-match', 'sleep',
            '--log', $fixture['log'],
            '--',
            ...$command,
        ];
        $process = new Process($arguments, null, $environment);
        $process->setTimeout(20);
        $process->run();

        return $process;
    }

    private function findExecutable(string $name): string
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            $candidate = rtrim($directory, '/').'/'.$name;
            if (is_executable($candidate) && ! is_dir($candidate)) {
                return $candidate;
            }
        }

        $this->fail("Could not find required executable {$name}.");
    }

    private function removeFixture(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $pidPath = $root.'/producer.pid';
        if (is_file($pidPath)) {
            $pid = (int) trim((string) file_get_contents($pidPath));
            if ($pid > 1 && function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
}
