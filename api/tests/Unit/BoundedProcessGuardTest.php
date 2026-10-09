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

    public function test_configured_swap_floor_rejects_worker_before_start(): void
    {
        $fixture = $this->fixture(700000);
        $marker = $fixture['root'].'/worker-started';

        try {
            $process = $this->runGuard($fixture, [
                'BIYAHE_RESOURCE_MIN_SWAPFREE_KB' => '131072',
            ], ['bash', '-c', 'touch "$1"', 'worker', $marker]);

            $this->assertSame(4, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('reason=swapfree_0_below_131072', $process->getOutput());
            $this->assertStringContainsString('command_not_started=yes', $process->getOutput());
            $this->assertFileDoesNotExist($marker);
        } finally {
            $this->removeFixture($fixture['root']);
        }
    }

    public function test_resource_preflight_samples_after_a_volatile_swap_drop_and_blocks(): void
    {
        $fixture = $this->fixture(700000, 200000);
        $marker = $fixture['root'].'/worker-started';
        $fluctuator = new Process([
            'bash', '-c',
            'for n in {1..100}; do if grep -q "resource_preflight_sample=1/3" "$2" 2>/dev/null; then break; fi; sleep 0.1; done; printf "MemTotal: 1024000 kB\\nMemAvailable: 700000 kB\\nCached: 1234 kB\\nSReclaimable: 4321 kB\\nSwapCached: 0 kB\\nSwapTotal: 262144 kB\\nSwapFree: 80000 kB\\n" > "$1"',
            'fluctuator', $fixture['meminfo'], $fixture['log'],
        ]);
        $fluctuator->setTimeout(5);
        $fluctuator->start();

        try {
            $process = $this->runGuard($fixture, [
                'BIYAHE_RESOURCE_MIN_SWAPFREE_KB' => '131072',
                'BIYAHE_RESOURCE_PREFLIGHT_SAMPLES' => '3',
                'BIYAHE_RESOURCE_PREFLIGHT_INTERVAL_SECONDS' => '1',
            ], ['bash', '-c', 'touch "$1"', 'worker', $marker]);
            $fluctuator->wait();

            $this->assertSame(4, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('resource_preflight_sample=1/3', $process->getOutput());
            $this->assertStringContainsString('resource_preflight_sample=1/3 memavailable_kb=700000 cached_kb=1234 sreclaimable_kb=4321 swapcached_kb=0 swapfree_kb=200000', $process->getOutput());
            $this->assertStringContainsString('resource_preflight_sample=2/3', $process->getOutput());
            $this->assertStringContainsString('resource_preflight_sample=2/3 memavailable_kb=700000 cached_kb=1234 sreclaimable_kb=4321 swapcached_kb=0 swapfree_kb=80000', $process->getOutput());
            $this->assertStringContainsString('resource_preflight_sample=3/3', $process->getOutput());
            $this->assertStringContainsString('reason=swapfree_80000_below_131072', $process->getOutput());
            $this->assertStringContainsString('command_not_started=yes', $process->getOutput());
            $this->assertFileDoesNotExist($marker);
        } finally {
            if ($fluctuator->isRunning()) {
                $fluctuator->stop(1);
            }
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
            $this->assertStringContainsString('operation=complete worker_exit=0 worker_log=', $process->getOutput());
            $this->assertStringContainsString('peak_rss_kb=4000', $process->getOutput());
            $this->assertFileDoesNotExist($fixture['log'].'.runner');
            $this->assertFileExists($fixture['log'].'.worker.log');
        } finally {
            $this->removeFixture($fixture['root']);
        }
    }

    public function test_postgres_rss_sampler_matches_android_postmaster_and_worker_titles(): void
    {
        $fixture = $this->fixture(700000);
        $marker = $fixture['root'].'/worker-complete';

        try {
            $process = $this->runGuard($fixture, [
                'BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB' => '524288',
                'BIYAHE_RESOURCE_MIN_DISKFREE_KB' => '524288',
                'BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB' => '65536',
                'FIXTURE_PG_MAIN_PID' => '99001',
                'FIXTURE_PG_WORKER_PID' => '99002',
                'FIXTURE_PG_MAIN_RSS' => '12000',
                'FIXTURE_PG_WORKER_RSS' => '8000',
            ], ['bash', '-c', 'printf complete > "$1"; sleep 1.4', 'worker', $marker]);

            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('postgres_rss_kb=20000', $process->getOutput());
            $this->assertStringContainsString('peak_postgres_rss_kb=20000', $process->getOutput());
            $this->assertFileDoesNotExist($fixture['log'].'.runner');
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
            $this->assertStringContainsString('resource_process_tree=', $process->getOutput());
            $this->assertStringContainsString('producer_stop=SIGTERM', $process->getOutput());
            $this->assertStringContainsString('operation=stopped_for_resource_budget', $process->getOutput());
            $this->assertFileExists($partial);
            $this->assertSame('', file_get_contents($partial));
            $this->assertFileDoesNotExist($fixture['log'].'.runner');
            $this->assertFileExists($fixture['log'].'.worker.log');
        } finally {
            $this->removeFixture($fixture['root']);
        }
    }

    public function test_resource_breach_cancels_only_the_private_worker_group_and_keeps_logs(): void
    {
        $fixture = $this->fixture(700000, 200000);
        $worker = $fixture['root'].'/hanging-worker.sh';
        $childPidFile = $fixture['root'].'/child.pid';
        $parentPidFile = $fixture['root'].'/parent.pid';
        $parentSurvivedFile = $fixture['root'].'/parent-survived';
        file_put_contents($worker, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
trap '' TERM
printf 'owned-worker-started\n'
sleep 90 &
child=$!
printf '%s\n' "$child" > "$FIXTURE_CHILD_PID_FILE"
printf '%s\n' "$child" > "$FIXTURE_PID_FILE"
while :; do sleep 1; done
BASH);
        chmod($worker, 0700);
        $unrelated = new Process(['bash', '-c', 'exec sleep 90']);
        $unrelated->setTimeout(10);
        $unrelated->start();

        try {
            $guard = $this->guardArguments($fixture, ['bash', $worker]);
            $environment = $this->guardEnvironment($fixture, [
                'BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB' => '65536',
                'BIYAHE_RESOURCE_BREACH_SAMPLES' => '1',
                'BIYAHE_RESOURCE_CANCEL_GRACE_SECONDS' => '1',
                'FIXTURE_CHILD_PID_FILE' => $childPidFile,
            ]);
            $parentCommand = [
                'bash', '-c',
                'printf "%s\\n" "$$" > "$1"; survived_file=$2; shift 2; "$@" & guard_pid=$!; guard_status=0; wait "$guard_pid" || guard_status=$?; printf survived > "$survived_file"; exit "$guard_status"',
                'parent-shell', $parentPidFile, $parentSurvivedFile, ...$guard,
            ];
            $process = new Process($parentCommand, null, $environment);
            $process->setTimeout(15);
            $process->run();

            $this->assertSame(6, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('worker_group=verified', $process->getOutput());
            $this->assertStringContainsString('worker_group_stop=SIGTERM', $process->getOutput());
            $this->assertStringContainsString('worker_group_stop=SIGKILL', $process->getOutput());
            $this->assertStringContainsString('worker_group_cancel=COMPLETE', $process->getOutput());
            $this->assertFileExists($parentSurvivedFile, 'The wrapper parent shell must remain alive after worker cancellation.');
            $this->assertSame('survived', file_get_contents($parentSurvivedFile));
            $this->assertTrue($unrelated->isRunning(), 'The unrelated sibling process must survive worker-group cancellation.');
            $childPid = (int) trim((string) file_get_contents($childPidFile));
            $this->assertFalse($this->isLiveProcess($childPid), 'The worker child must be stopped with its owned group.');
            $this->assertFileExists($fixture['log']);
            $this->assertFileExists($fixture['log'].'.worker.log');
            $this->assertStringContainsString('owned-worker-started', (string) file_get_contents($fixture['log'].'.worker.log'));
            $this->assertFileDoesNotExist($fixture['log'].'.runner');
        } finally {
            if ($unrelated->isRunning()) {
                $unrelated->stop(1);
            }
            $this->removeFixture($fixture['root']);
        }
    }

    /** @return array{root: string, bin: string, log: string, meminfo: string, calls: string, runnerPid: string, producerPid: string, realPs: string} */
    private function fixture(int $availableMemory, int $freeSwap = 0): array
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
        file_put_contents($meminfo, "MemTotal: 1024000 kB\nMemAvailable: ".$availableMemory." kB\nCached: 1234 kB\nSReclaimable: 4321 kB\nSwapCached: 0 kB\nSwapTotal: 262144 kB\nSwapFree: ".$freeSwap." kB\n");

        $realPs = $this->findExecutable('ps');
        $ps = <<<'BASH'
#!/usr/bin/env bash
has_all=0
has_pid=0
requested_pid=
format=
format_follows_option=0
for argument in "$@"; do
  [[ "$argument" == -e || "$argument" == -A ]] && has_all=1
  if [[ "$format_follows_option" == 1 ]]; then format="$argument"; format_follows_option=0; fi
  [[ "$argument" == -o ]] && format_follows_option=1
  if [[ "$has_pid" == 1 ]]; then requested_pid="$argument"; has_pid=0; fi
  [[ "$argument" == -p ]] && has_pid=1
done
if [[ "$has_all" == 1 ]]; then
  if [[ "$format" == 'pid=,ppid=,pgid=,sid=,rss=,stat=' || "$format" == 'pid=,ppid=,pgid=,sid=,rss=,uid=,comm=' ]]; then
    exec "$REAL_PS" "$@"
  fi
  if [[ "$format" == 'pid=,rss=,args=' ]]; then
    printf '%s %s /data/data/com.termux/files/usr/bin/postgres -D /private/isolated\n' "${FIXTURE_PG_MAIN_PID:-}" "${FIXTURE_PG_MAIN_RSS:-0}"
    printf '%s %s postgres: background writer\n' "${FIXTURE_PG_WORKER_PID:-}" "${FIXTURE_PG_WORKER_RSS:-0}"
    exit 0
  fi
  runner="$(cat "$FIXTURE_RUNNER_PID" 2>/dev/null || true)"
  producer="$(cat "$FIXTURE_PID_FILE" 2>/dev/null || true)"
  if [[ "$runner" =~ ^[0-9]+$ ]]; then
    printf '%s 1 4000\n' "$runner"
    if [[ "$producer" =~ ^[0-9]+$ ]]; then printf '%s %s 80000\n' "$producer" "$runner"; fi
  fi
  exit 0
fi
if [[ "$requested_pid" =~ ^[0-9]+$ ]]; then
  if [[ "$requested_pid" == "${FIXTURE_PG_MAIN_PID:-}" ]]; then printf '%s\n' "${FIXTURE_PG_MAIN_RSS:-0}"; exit 0; fi
  if [[ "$requested_pid" == "${FIXTURE_PG_WORKER_PID:-}" ]]; then printf '%s\n' "${FIXTURE_PG_WORKER_RSS:-0}"; exit 0; fi
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
        $process = new Process($this->guardArguments($fixture, $command), null, $this->guardEnvironment($fixture, $extraEnvironment));
        $process->setTimeout(20);
        $process->run();

        return $process;
    }

    /** @param list<string> $command @return list<string> */
    private function guardArguments(array $fixture, array $command): array
    {
        $script = dirname(__DIR__, 2).'/scripts/run-bounded-process.sh';

        return [
            'bash', $script,
            '--operation', 'test-worker',
            '--disk-path', $fixture['root'],
            '--cancel-pid-file', $fixture['producerPid'],
            '--cancel-match', 'sleep',
            '--log', $fixture['log'],
            '--',
            ...$command,
        ];
    }

    /** @param array<string, string> $extraEnvironment @return array<string, string> */
    private function guardEnvironment(array $fixture, array $extraEnvironment): array
    {
        return array_merge([
            'PATH' => $fixture['bin'].':'.(getenv('PATH') ?: ''),
            'REAL_PS' => $fixture['realPs'],
            'FIXTURE_RUNNER_PID' => $fixture['runnerPid'],
            'FIXTURE_PID_FILE' => $fixture['producerPid'],
            'BIYAHE_RESOURCE_MEMINFO_PATH' => $fixture['meminfo'],
            'BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB' => '524288',
            'BIYAHE_RESOURCE_MIN_SWAPFREE_KB' => '0',
            'BIYAHE_RESOURCE_MIN_DISKFREE_KB' => '524288',
            'BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB' => '262144',
            'BIYAHE_RESOURCE_BREACH_SAMPLES' => '1',
            'BIYAHE_RESOURCE_POLL_SECONDS' => '1',
            'BIYAHE_RESOURCE_CANCEL_GRACE_SECONDS' => '5',
            'BIYAHE_RESOURCE_PREFLIGHT_SAMPLES' => '1',
            'BIYAHE_RESOURCE_PREFLIGHT_INTERVAL_SECONDS' => '1',
        ], $extraEnvironment);
    }

    private function isLiveProcess(int $pid): bool
    {
        $path = '/proc/'.$pid.'/stat';
        if ($pid < 2 || ! is_file($path)) {
            return false;
        }
        $stat = (string) file_get_contents($path);
        $separator = strrpos($stat, ') ');
        if ($separator === false) {
            return false;
        }
        $fields = preg_split('/\\s+/', trim(substr($stat, $separator + 2)));

        return is_array($fields) && ($fields[0] ?? null) !== 'Z';
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
