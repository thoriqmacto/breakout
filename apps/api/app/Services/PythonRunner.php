<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Illuminate\Support\Arr;

class PythonRunner
{
    public function __construct(
        private string $pythonBin = '',
        private string $scriptsPath = '',
        private int $timeout = 60
    ) {
        $this->pythonBin = $this->pythonBin ?: config('python.bin');
        $this->scriptsPath = $this->scriptsPath ?: config('python.scripts_path');
        $this->timeout = $this->timeout ?: (int) config('python.timeout', 60);
    }

    /**
     * Run a python script with optional JSON payload.
     *
     * @param  string $scriptName e.g. 'hello.py'
     * @param  array|null $jsonPayload Data sent to STDIN as JSON
     * @param  array $args Extra CLI args, e.g. ['--flag', 'value']
     * @param  array $env Extra environment variables
     * @return array{ok:bool, exit_code:int, stdout:string, stderr:string, json:mixed|null}
     */
    public function run(string $scriptName, ?array $jsonPayload = null, array $args = [], array $env = []): array
    {
        // SECURITY: build a safe absolute path and prevent directory traversal
        $scriptPath = realpath($this->scriptsPath . DIRECTORY_SEPARATOR . $scriptName);
        if (!$scriptPath || !str_starts_with($scriptPath, realpath($this->scriptsPath))) {
            throw new \RuntimeException("Invalid script path.");
        }

        // Build command array (avoid shell to prevent injection)
        $command = Arr::flatten([
            $this->pythonBin,
            $scriptPath,
            $args,
        ]);

        $process = new Process($command, base_path(), $env, null, $this->timeout);

        // If sending JSON to stdin
        if ($jsonPayload !== null) {
            $process->setInput(json_encode($jsonPayload, JSON_UNESCAPED_SLASHES));
        }

        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $exit  = $process->getExitCode();
        $ok    = $process->isSuccessful();

        $decoded = null;
        if ($stdout !== '') {
            try { $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) {}
        }

        return [
            'ok'        => $ok,
            'exit_code' => (int) $exit,
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'json'      => $decoded,
        ];
    }
}
