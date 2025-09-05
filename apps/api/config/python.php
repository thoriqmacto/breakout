<?php

return [
    // Absolute path to your python binary.
    // For system python (Linux/macOS) often just 'python3' works.
    // For Windows, point to the full path if needed, e.g. 'C:\Python312\python.exe'
    // Or to your venv's interpreter, e.g. base_path('venv/bin/python').
    'bin' => env('PYTHON_BIN', 'python3'),

    // Base directory where your scripts live
    'scripts_path' => resource_path('python'),

    // Default timeout in seconds for process execution
    'timeout' => env('PYTHON_TIMEOUT', 60),
];
