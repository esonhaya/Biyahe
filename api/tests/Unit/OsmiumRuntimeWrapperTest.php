<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class OsmiumRuntimeWrapperTest extends TestCase
{
    public function test_termux_wrapper_replaces_an_inherited_library_path(): void
    {
        $directory = sys_get_temp_dir().'/biyahe-osmium-wrapper-'.bin2hex(random_bytes(6));
        $prefix = $directory.'/prefix';
        $bin = $directory.'/bin';
        mkdir($prefix.'/lib', 0700, true);
        mkdir($bin, 0700, true);
        $fakeOsmium = $bin.'/osmium';
        file_put_contents($fakeOsmium, "#!/bin/sh\nprintf '%s' \"\${LD_LIBRARY_PATH-}\"\n");
        chmod($fakeOsmium, 0700);

        try {
            $process = new Process(
                ['bash', '-c', 'source "$1"; run_osmium version', '_', dirname(__DIR__, 2).'/scripts/osmium-runtime.sh'],
                $directory,
                [
                    'PATH' => $bin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
                    'PREFIX' => $prefix,
                    'LD_LIBRARY_PATH' => $directory.'/incompatible-runtime',
                ],
                null,
                5,
            );
            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame($prefix.'/lib', $process->getOutput());
        } finally {
            @unlink($fakeOsmium);
            @rmdir($bin);
            @rmdir($prefix.'/lib');
            @rmdir($prefix);
            @rmdir($directory);
        }
    }
}
