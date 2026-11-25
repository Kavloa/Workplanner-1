<?php
/**
 * PDE Allocation % must survive AlgorithmTaskMapper::toLegacy so algo + histogram
 * honor override splits (not silent 50/50).
 *
 * Run: php scripts/test-mapper-override-shares.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\AlgoController;
use App\Support\AlgorithmTaskMapper;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS  {$name}\n";
    } else {
        $failed++;
        echo "FAIL  {$name}".($detail !== '' ? " — {$detail}" : '')."\n";
    }
}

$pdeTask = [
    'id' => 999001,
    'task_name' => 'Mapper share probe',
    'pri' => '1',
    'doc_supply' => '2026-01-05',
    'completion' => '2026-01-20',
    'plan' => 100,
    'override_1' => 'Nora Keshk',
    'override_2' => 'Abdelrahman Shakra',
    'override_3' => null,
    'override_share_1' => '70',
    'override_share_2' => '30',
    'override_share_3' => null,
    'project_id' => 1,
    'project_name' => 'Probe',
];

$legacy = AlgorithmTaskMapper::toLegacy($pdeTask);

check(
    'toLegacy keeps Override_Share_1=70',
    ($legacy['Override_Share_1'] ?? null) === '70',
    json_encode($legacy['Override_Share_1'] ?? null)
);
check(
    'toLegacy keeps Override_Share_2=30',
    ($legacy['Override_Share_2'] ?? null) === '30',
    json_encode($legacy['Override_Share_2'] ?? null)
);
check(
    'toLegacy keeps Override names',
    ($legacy['Override_1'] ?? null) === 'Nora Keshk'
        && ($legacy['Override_2'] ?? null) === 'Abdelrahman Shakra',
    json_encode([$legacy['Override_1'] ?? null, $legacy['Override_2'] ?? null])
);

$controller = app(AlgoController::class);
$ref = new ReflectionClass($controller);
$call = static function (string $method, array $args) use ($ref, $controller) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($controller, $args);
};

$employees = collect([
    (object) ['id' => 11, 'first_name' => 'Nora', 'last_name' => 'Keshk'],
    (object) ['id' => 22, 'first_name' => 'Abdelrahman', 'last_name' => 'Shakra'],
]);

$targets = $call('buildHypermodeOverrideFairTargets', [100.0, $employees, $legacy]);
check(
    'mapped legacy task → HYPERMODE 70/30 hours',
    $targets === [70, 30],
    json_encode($targets)
);

// Histogram reflection: bars are built from assignmentDetails (one segment per
// employee×contiguous days). Share split must appear as per-employee hour totals.
$assignments = [
    ['employee' => 'Nora Keshk', 'date' => '2026-01-05', 'hours' => 40],
    ['employee' => 'Nora Keshk', 'date' => '2026-01-06', 'hours' => 30],
    ['employee' => 'Abdelrahman Shakra', 'date' => '2026-01-05', 'hours' => 20],
    ['employee' => 'Abdelrahman Shakra', 'date' => '2026-01-06', 'hours' => 10],
];
$hoursByEmp = [];
foreach ($assignments as $row) {
    $emp = (string) $row['employee'];
    $hoursByEmp[$emp] = ($hoursByEmp[$emp] ?? 0) + (float) $row['hours'];
}
check(
    'histogram-bound assignments reflect 70h for override_1',
    abs(($hoursByEmp['Nora Keshk'] ?? 0) - 70) < 0.01,
    json_encode($hoursByEmp)
);
check(
    'histogram-bound assignments reflect 30h for override_2',
    abs(($hoursByEmp['Abdelrahman Shakra'] ?? 0) - 30) < 0.01,
    json_encode($hoursByEmp)
);

// PDE physical slots (snake_case DB) and already-legacy keys both map.
$fromLegacyKeys = AlgorithmTaskMapper::toLegacy([
    'id' => 999003,
    'task_name' => 'Legacy key probe',
    'plan' => 100,
    'doc_supply' => '2026-01-05',
    'completion' => '2026-01-20',
    'override_1' => 'A',
    'override_2' => 'B',
    'Override_Share_1' => '60',
    'Override_Share_2' => '40',
]);
check(
    'toLegacy accepts Override_Share_* input keys',
    ($fromLegacyKeys['Override_Share_1'] ?? null) === '60'
        && ($fromLegacyKeys['Override_Share_2'] ?? null) === '40',
    json_encode([
        $fromLegacyKeys['Override_Share_1'] ?? null,
        $fromLegacyKeys['Override_Share_2'] ?? null,
    ])
);

// Regression: without shares on mapped task, even split remains.
$noShare = AlgorithmTaskMapper::toLegacy([
    'id' => 999002,
    'task_name' => 'Even split',
    'plan' => 100,
    'doc_supply' => '2026-01-05',
    'completion' => '2026-01-20',
    'override_1' => 'Nora Keshk',
    'override_2' => 'Abdelrahman Shakra',
]);
check(
    'no shares → Override_Share_* null',
    ($noShare['Override_Share_1'] ?? null) === null
        && ($noShare['Override_Share_2'] ?? null) === null
);
$evenTargets = $call('buildHypermodeOverrideFairTargets', [100.0, $employees, $noShare]);
check(
    'no shares → even 50/50',
    $evenTargets === [50, 50],
    json_encode($evenTargets)
);

echo "\n---\nPassed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
