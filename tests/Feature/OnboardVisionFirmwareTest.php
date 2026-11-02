<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OnboardVisionFirmwareTest extends TestCase
{
    public function test_onboard_target_geometry_confidence_and_class_contract(): void
    {
        $directory = storage_path('framework/testing/onboard-'.Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            $compiler = ['g++'];
            $source = base_path('ArduinoCam/OnboardVision/tests/target_detection_test.cpp');
            $headers = base_path('ArduinoCam/OnboardVision/main');
            $binary = $directory.'/target_detection_test';
            $run = [$binary];

            if (PHP_OS_FAMILY === 'Windows') {
                $wsl = (new ExecutableFinder)->find('wsl');
                if ($wsl === null) {
                    $this->markTestSkipped('A host C++ compiler in WSL is required on Windows.');
                }
                $prefix = [$wsl, '-d', 'RobotSys-Ubuntu', '-u', 'robotsys', '--exec'];
                $probe = new Process([...$prefix, 'g++', '--version']);
                $probe->setTimeout(30)->run();
                if (! $probe->isSuccessful()) {
                    $this->markTestSkipped('The RobotSys WSL C++ toolchain is unavailable.');
                }
                $source = $this->linuxPath($source);
                $headers = $this->linuxPath($headers);
                $binary = $this->linuxPath($binary);
                $compiler = [...$prefix, 'g++'];
                $run = [...$prefix, $binary];
            } elseif ((new ExecutableFinder)->find('g++') === null) {
                $this->markTestSkipped('A host C++ compiler is required for firmware contract tests.');
            }

            $compile = new Process([...$compiler, '-std=c++17', '-Wall', '-Wextra', '-Werror', '-I', $headers, $source, '-o', $binary]);
            $compile->setTimeout(90)->run();
            $this->assertSame(0, $compile->getExitCode(), $compile->getErrorOutput().$compile->getOutput());

            $contract = new Process($run);
            $contract->setTimeout(30)->run();
            $this->assertSame(0, $contract->getExitCode(), $contract->getErrorOutput().$contract->getOutput());
        } finally {
            $root = realpath(storage_path('framework/testing'));
            $target = realpath($directory);
            if ($root !== false && $target !== false && str_starts_with($target, $root.DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($target);
            }
        }
    }

    private function linuxPath(string $path): string
    {
        return '/mnt/'.strtolower($path[0]).str_replace('\\', '/', substr($path, 2));
    }
}
