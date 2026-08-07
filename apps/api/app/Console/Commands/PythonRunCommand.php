<?php

namespace App\Console\Commands;

use App\Services\PythonRunner;
use Illuminate\Console\Command;

class PythonRunCommand extends Command
{
    protected $signature = 'python:run
        {script : Script filename under resources/python}
        {--json= : JSON string or @file.json path to pass on STDIN}
        {--arg=* : Additional CLI args}';

    protected $description = 'Run a Python script from resources/python';

    public function handle(PythonRunner $runner): int
    {
        $script = $this->argument('script');
        $jsonOpt = $this->option('json');
        $args = $this->option('arg');

        $payload = null;

        if ($jsonOpt) {
            // If starts with "@", treat it as a file path
            if (str_starts_with($jsonOpt, '@')) {
                $filePath = substr($jsonOpt, 1);
                if (! is_file($filePath)) {
                    $this->error("JSON file not found: {$filePath}");

                    return self::FAILURE;
                }
                $jsonOpt = file_get_contents($filePath);
            }

            try {
                $payload = json_decode($jsonOpt, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $this->error('Invalid JSON: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $res = $runner->run($script, $payload, $args);

        $this->line("Exit: {$res['exit_code']}");
        if ($res['stderr']) {
            $this->warn($res['stderr']);
        }
        $this->info($res['stdout']); // Print raw Python output

        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
