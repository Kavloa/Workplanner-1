<?php
/**
 * Ensure displacing a higher-priority booking emits a warning.
 * Run: php scripts/test-instant-override-high-pri-warning.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Support\AlgorithmCache;
use App\Support\InstantOverrideScheduleApply;

$cached = AlgorithmCache::get() ?? [];
$details = is_array($cached['assignmentDetails'] ?? null) ? $cached['assignmentDetails'] : [];

// Seed a synthetic higher-pri task occupying Nora fully on the first days.
$details = array_values(array_filter(
    $details,
    static fn ($d) => ! in_array((int) ($d['task_id'] ?? 0), [900001, 900002], true),
));

$details[] = [
    'task_id' => 900001,
    'TaskName' => 'HIGH PRI blocker',
    'Pri' => 1.0,
    'Plan' => 40,
    'mode' => 'TEST',
    'assignments' => [
        ['employee' => 'Nora Keshk', 'date' => '2026-11-11', 'hours' => 8],
        ['employee' => 'Nora Keshk', 'date' => '2026-11-12', 'hours' => 8],
        ['employee' => 'Nora Keshk', 'date' => '2026-11-13', 'hours' => 8],
        ['employee' => 'Rehaf Hamed', 'date' => '2026-11-11', 'hours' => 8],
        ['employee' => 'Rehaf Hamed', 'date' => '2026-11-12', 'hours' => 8],
    ],
];

AlgorithmCache::put([
    ...$cached,
    'assignmentDetails' => $details,
]);

$fakeTask = [
    'id' => 900002,
    'task_name' => 'Split probe',
    'plan' => 20,
    'pri' => 50,
    'doc_supply' => '2026-11-11',
    'completion' => '2026-11-20',
    'override_1' => 'Nora Keshk',
    'override_2' => 'Rehaf Hamed',
    'override_share_1' => '50',
    'override_share_2' => '50',
    'done' => false,
];

$impact = InstantOverrideScheduleApply::applyForTasks([$fakeTask]);
echo json_encode($impact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

$ok = ($impact['applied'] ?? false)
    && ($impact['displaced_higher'] ?? []) !== []
    && collect($impact['warnings'] ?? [])->contains(fn ($w) => str_contains(strtolower($w), 'higher-priority'));

// Cleanup synthetic rows
$cached = AlgorithmCache::get() ?? [];
$details = array_values(array_filter(
    is_array($cached['assignmentDetails'] ?? null) ? $cached['assignmentDetails'] : [],
    static fn ($d) => ! in_array((int) ($d['task_id'] ?? 0), [900001, 900002], true),
));
AlgorithmCache::put([
    ...$cached,
    'assignmentDetails' => $details,
]);

if (! $ok) {
    fwrite(STDERR, "FAIL expected higher-priority warning\n");
    exit(1);
}

echo "PASS higher-priority displacement warns\n";
exit(0);
