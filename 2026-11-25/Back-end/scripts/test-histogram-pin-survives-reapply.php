<?php

/**
 * Ensure histogram pins displace other-task hours on reapply (save+algo path).
 * Run: php scripts/test-histogram-pin-survives-reapply.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\HistogramScheduleOverrides;

$employee = 'Pin Test Operative';
$pinDay = '2026-12-01';
$pinHours = 8.0;

$details = [
    [
        'task_id' => 1001,
        'Pri' => 1,
        'assignments' => [
            ['employee' => $employee, 'date' => $pinDay, 'hours' => 0.0],
        ],
    ],
    [
        'task_id' => 1002,
        'Pri' => 5,
        'assignments' => [
            ['employee' => $employee, 'date' => $pinDay, 'hours' => 8.0],
        ],
    ],
];

// Simulate algo occupying the pin day on another task; pin wants that day.
$after = HistogramScheduleOverrides::replaceEmployeeSchedule(
    $details,
    1001,
    $employee,
    [$pinDay => $pinHours],
);

$pinned = HistogramScheduleOverrides::extractEmployeeHours($after, 1001, $employee);
$other = HistogramScheduleOverrides::extractEmployeeHours($after, 1002, $employee);

$pinOk = abs(($pinned[$pinDay] ?? 0) - $pinHours) < 0.05;
$otherCleared = ($other[$pinDay] ?? 0) < 0.05;

echo 'Pinned hours on '.$pinDay.': '.json_encode($pinned).PHP_EOL;
echo 'Other task hours on '.$pinDay.': '.json_encode($other).PHP_EOL;

if ($pinOk && $otherCleared) {
    echo "PASS  Pin displaces other-task hours on reapply\n";
    exit(0);
}

echo 'FAIL  pin_ok='.($pinOk ? 'Y' : 'N').' other_cleared='.($otherCleared ? 'Y' : 'N')."\n";
exit(1);
