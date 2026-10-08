<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

class PostgresResourceGuardTest extends TestCase
{
    public function test_start_budget_rejects_insufficient_available_memory_without_starting(): void
    {
        $fixture = $this->fixture(700000);

        try {
            $process = $this->observer($fixture, [
                'BIYAHE_PG_START_MIN_MEMAVAILABLE_KB' => '800000',
            ], ['--start', '--duration-seconds', '1', '--interval-seconds', '5']);

            $this->assertSame(4, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('startup=blocked; configured resource gate not met', $process->getOutput());
            $this->assertSame("status\n", file_get_contents($fixture['calls']));
            $this->assertFileDoesNotExist($fixture['pgdata'].'/postmaster.pid');
        } finally {
            $this->removeFixture($fixture['root']);
        }
    }

    public function test_start_uses_operation_budget_and_gracefully_stops_after_measured_rss_breach(): void
    {
        $fixture = $this->fixture(700000);

        try {
            $process = $this->observer($fixture, [
                'BIYAHE_PG_START_MIN_MEMAVAILABLE_KB' => '524288',
                'BIYAHE_PG_START_MIN_SWAPFREE_KB' => '0',
                'BIYAHE_PG_START_MIN_DISKFREE_KB' => '262144',
                'BIYAHE_PG_MAX_PROCESS_TREE_RSS_KB' => '196608',
            ], ['--start', '--duration-seconds', '10', '--interval-seconds', '5']);

            $this->assertSame(6, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('startup=server_detected_after_single_attempt', $process->getOutput());
            $this->assertStringContainsString('process_tree_rss_kb=250000', $process->getOutput());
            $this->assertStringContainsString('resource_stop=graceful_fast_shutdown_completed', $process->getOutput());
            $this->assertSame("status\nstart\nstatus\nstop:fast\nstatus\n", file_get_contents($fixture['calls']));
            $this->assertFileDoesNotExist($fixture['pgdata'].'/postmaster.pid');
            $this->assertSame([], glob($fixture['runtime'].'/.postgres-start.*') ?: []);
            $this->assertSame([], glob($fixture['runtime'].'/.postgres-stop.*') ?: []);
            $this->assertStringContainsString('swapfree_kb=0', $process->getOutput());
        } finally {
            $this->removeFixture($fixture['root']);
        }
    }

    public function test_failed_normal_start_is_not_retried_and_capture_file_is_cleaned(): void
    {
        $fixture = $this->fixture(700000);
        file_put_contents($fixture['startMode'], 'fail');

        try {
            $process = $this->observer($fixture, [
                'BIYAHE_PG_START_MIN_MEMAVAILABLE_KB' => '524288',
                'BIYAHE_PG_START_MIN_SWAPFREE_KB' => '0',
                'BIYAHE_PG_START_MIN_DISKFREE_KB' => '262144',
            ], ['--start', '--duration-seconds', '1', '--interval-seconds', '5']);

            $this->assertSame(5, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('startup=failed_or_not_ready; no retry issued', $process->getOutput());
            $this->assertSame("status\nstart\nstatus\n", file_get_contents($fixture['calls']));
            $this->assertSame([], glob($fixture['runtime'].'/.postgres-start.*') ?: []);
        } finally {
            $this->removeFixture($fixture['root']);
        }
    }

    /** @return array{root: string, runtime: string, pgdata: string, calls: string, meminfo: string, startMode: string, bin: string} */
    private function fixture(int $availableMemory): array
    {
        $root = sys_get_temp_dir().'/biyahe-pg-guard-'.bin2hex(random_bytes(6));
        $runtime = $root.'/runtime';
        $pgdata = $runtime.'/postgres';
        $bin = $root.'/bin';
        mkdir($pgdata, 0700, true);
        mkdir($bin, 0700, true);
        $calls = $root.'/pg-ctl-calls.log';
        $meminfo = $root.'/meminfo';
        $startMode = $root.'/start-mode';
        file_put_contents($calls, '');
        file_put_contents($startMode, 'ok');
        file_put_contents($meminfo, "MemTotal: 1024000 kB\nMemAvailable: {$availableMemory} kB\nSwapTotal: 0 kB\nSwapFree: 0 kB\n");

        $pgCtl = <<<'BASH'
#!/usr/bin/env bash
set -u
pgdata="${FAKE_PGDATA:?}"
operation="${!#}"
case "$operation" in
  status)
    printf 'status\n' >> "$FAKE_CALLS"
    if [[ -r "$pgdata/postmaster.pid" ]]; then
      IFS= read -r pid < "$pgdata/postmaster.pid" || true
      [[ "${pid:-}" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null && exit 0
    fi
    exit 3
    ;;
  start)
    printf 'start\n' >> "$FAKE_CALLS"
    if [[ "$(cat "$FAKE_START_MODE")" == fail ]]; then
      printf 'simulated startup failure\n'
      exit 1
    fi
    sleep 90 &
    child=$!
    printf '%s\n' "$child" > "$pgdata/postmaster.pid"
    printf 'simulated startup complete\n'
    ;;
  stop)
    mode=unknown
    previous=
    for argument in "$@"; do
      if [[ "$previous" == -m ]]; then mode="$argument"; fi
      previous="$argument"
    done
    printf 'stop:%s\n' "$mode" >> "$FAKE_CALLS"
    if [[ -r "$pgdata/postmaster.pid" ]]; then
      IFS= read -r pid < "$pgdata/postmaster.pid" || true
      [[ "${pid:-}" =~ ^[0-9]+$ ]] && kill "$pid" 2>/dev/null || true
      rm -f -- "$pgdata/postmaster.pid"
    fi
    printf 'simulated graceful stop complete\n'
    ;;
  *) exit 2 ;;
esac
BASH;
        file_put_contents($bin.'/pg_ctl', $pgCtl."\n");
        chmod($bin.'/pg_ctl', 0700);
        file_put_contents($bin.'/pg_isready', "#!/usr/bin/env bash\nexit 0\n");
        chmod($bin.'/pg_isready', 0700);

        $realPs = $this->findExecutable('ps');
        $psShim = <<<'BASH'
#!/usr/bin/env bash
for argument in "$@"; do
  if [[ "$argument" == -e ]]; then
    pid="$(head -n 1 "$FAKE_PGDATA/postmaster.pid" 2>/dev/null || true)"
    if [[ "${pid:-}" =~ ^[0-9]+$ ]]; then
      printf '%s 1 250000\n' "$pid"
    fi
    exit 0
  fi
done
exec "$REAL_PS" "$@"
BASH;
        file_put_contents($bin.'/ps', $psShim."\n");
        chmod($bin.'/ps', 0700);

        return compact('root', 'runtime', 'pgdata', 'calls', 'meminfo', 'startMode', 'bin') + ['realPs' => $realPs];
    }

    /** @param array<string, string> $extraEnvironment @param list<string> $arguments */
    private function observer(array $fixture, array $extraEnvironment, array $arguments): Process
    {
        $script = dirname(__DIR__, 2).'/scripts/observe-postgres.sh';
        $logPath = $fixture['runtime'].'/observer.log';
        $environment = array_merge([
            'PATH' => $fixture['bin'].':'.(getenv('PATH') ?: ''),
            'PG_CTL' => $fixture['bin'].'/pg_ctl',
            'PG_ISREADY' => $fixture['bin'].'/pg_isready',
            'BIYAHE_PG_RUNTIME_DIR' => $fixture['runtime'],
            'BIYAHE_PG_SOCKET_DIR' => $fixture['runtime'],
            'BIYAHE_PG_SERVER_LOG' => $fixture['runtime'].'/postgres.log',
            'BIYAHE_PG_MEMINFO_PATH' => $fixture['meminfo'],
            'FAKE_PGDATA' => $fixture['pgdata'],
            'FAKE_CALLS' => $fixture['calls'],
            'FAKE_START_MODE' => $fixture['startMode'],
            'REAL_PS' => $fixture['realPs'],
        ], $extraEnvironment);
        $process = new Process(array_merge(['bash', $script], $arguments, ['--log', $logPath]), null, $environment);
        $process->setTimeout(30);
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
        $pidPath = $root.'/runtime/postgres/postmaster.pid';
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
