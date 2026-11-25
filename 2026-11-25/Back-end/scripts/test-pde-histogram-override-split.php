<?php
/**
 * PDE ↔ histogram: override Allocation % survives mapper, drives fair targets,
 * and those hours appear as per-employee histogram bars.
 *
 * Run: php scripts/test-pde-histogram-override-split.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\AlgoController;
use App\Models\Nrm;
use App\Models\ProjectTask;
use App\Support\AlgorithmTaskMapper;
use App\Support\HistogramFromAssignments;

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

$controller = app(AlgoController::class);
$ref = new ReflectionClass($controller);
$call = static function (string $method, array $args) use ($ref, $controller) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($controller, $args);
};

$cases = [
    ['id' => 12256, 'label' => 'PDE 70/30 Win & Ext doors'],
    ['id' => 12194, 'label' => 'PDE 30/70 Internal walls'],
    ['id' => 12166, 'label' => 'PDE 80/20 Upper floors'],
];

$nrms = Nrm::query()->get()->keyBy('id');

foreach ($cases as $case) {
    $task = ProjectTask::query()
        ->with(['part:id,project_id,name', 'part.project:id,name,status,color'])
        ->find($case['id']);

    if (! $task) {
        check("{$case['label']}: task exists", false, 'missing');
        continue;
    }

    $legacy = AlgorithmTaskMapper::toLegacy($task->toArray() + [
        'project_id' => $task->part?->project_id,
        'project_name' => $task->part?->project?->name,
    ]);

    check(
        "{$case['label']}: mapper carries shares",
        ($legacy['Override_Share_1'] ?? null) === (string) $task->override_share_1
            && ($legacy['Override_Share_2'] ?? null) === (string) $task->override_share_2,
        json_encode([
            'db' => [$task->override_share_1, $task->override_share_2],
            'legacy' => [$legacy['Override_Share_1'] ?? null, $legacy['Override_Share_2'] ?? null],
        ])
    );

    $employees = collect([
        (object) [
            'id' => 1,
            'first_name' => explode(' ', trim((string) $task->override_1), 2)[0] ?? 'A',
            'last_name' => explode(' ', trim((string) $task->override_1), 2)[1] ?? '',
        ],
        (object) [
            'id' => 2,
            'first_name' => explode(' ', trim((string) $task->override_2), 2)[0] ?? 'B',
            'last_name' => explode(' ', trim((string) $task->override_2), 2)[1] ?? '',
        ],
    ]);

    // Align names exactly as overrides for roster matching.
    $employees[0]->first_name = trim((string) $task->override_1);
    $employees[0]->last_name = '';
    $employees[1]->first_name = trim((string) $task->override_2);
    $employees[1]->last_name = '';

    $plan = (float) $task->plan;
    $targets = $call('buildHypermodeOverrideFairTargets', [$plan, $employees, $legacy]);
    $share1 = (float) $task->override_share_1;
    $share2 = (float) $task->override_share_2;
    $expected = $call('splitPlanByPercents', [$plan, [$share1, $share2]]);

    check(
        "{$case['label']}: fair targets match PDE shares",
        $targets === $expected,
        'targets='.json_encode($targets).' expected='.json_encode($expected)
    );

    $ov1 = trim((string) $task->override_1);
    $ov2 = trim((string) $task->override_2);
    $doc = $task->doc_supply
        ? \Carbon\Carbon::parse($task->doc_supply)->format('Y-m-d')
        : '2026-01-05';
    $day2 = \Carbon\Carbon::parse($doc)->addWeekday()->format('Y-m-d');

    // Split each target across two contiguous days so HistogramFromAssignments groups one bar/emp.
    $h1a = (int) floor($targets[0] / 2);
    $h1b = (int) $targets[0] - $h1a;
    $h2a = (int) floor($targets[1] / 2);
    $h2b = (int) $targets[1] - $h2a;

    $assignmentDetails = [[
        'task_id' => (int) $task->id,
        'mode' => 'HYPERMODE_OVERRIDE_EVEN_SPLIT',
        'assignments' => array_values(array_filter([
            $h1a > 0 ? ['employee' => $ov1, 'date' => $doc, 'hours' => $h1a] : null,
            $h1b > 0 ? ['employee' => $ov1, 'date' => $day2, 'hours' => $h1b] : null,
            $h2a > 0 ? ['employee' => $ov2, 'date' => $doc, 'hours' => $h2a] : null,
            $h2b > 0 ? ['employee' => $ov2, 'date' => $day2, 'hours' => $h2b] : null,
        ])),
    ]];

    $built = HistogramFromAssignments::build(
        $assignmentDetails,
        collect([(int) $task->id => $task]),
        $nrms
    );

    $hoursByEmp = [];
    foreach ($built['bars'] as $bar) {
        $emp = (string) ($bar['employee'] ?? '');
        $hoursByEmp[$emp] = ($hoursByEmp[$emp] ?? 0) + (float) ($bar['hours'] ?? 0);
    }

    check(
        "{$case['label']}: histogram bars match override split",
        abs(($hoursByEmp[$ov1] ?? -1) - $targets[0]) < 0.01
            && abs(($hoursByEmp[$ov2] ?? -1) - $targets[1]) < 0.01,
        json_encode([
            'targets' => $targets,
            'bars' => $hoursByEmp,
            'bar_count' => count($built['bars']),
        ])
    );
}

echo "\n---\nPassed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
