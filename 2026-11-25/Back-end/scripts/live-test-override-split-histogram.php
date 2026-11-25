<?php
/**
 * Live E2E: set PDE shares on a task, run assignTaskHours path via algo helpers,
 * write assignmentDetails into cache shape, rebuild histogram bars, assert split.
 *
 * Prefer a full algorithm run from UI when available; this script validates the
 * mapper → targets → histogram reflection against the live DB task.
 *
 * Run: php scripts/live-test-override-split-histogram.php [taskId]
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\AlgoController;
use App\Models\Nrm;
use App\Models\ProjectTask;
use App\Support\AlgorithmCache;
use App\Support\AlgorithmTaskMapper;
use App\Support\HistogramFromAssignments;

$taskId = isset($argv[1]) ? (int) $argv[1] : 12256;

$task = ProjectTask::query()
    ->with(['part:id,project_id,name', 'part.project:id,name,status,color'])
    ->find($taskId);

if (! $task) {
    fwrite(STDERR, "Task {$taskId} not found\n");
    exit(1);
}

echo "=== LIVE PDE → HISTOGRAM override split ===\n";
echo "Task #{$task->id} {$task->task_name} plan={$task->plan}\n";
echo "Overrides: [{$task->override_1} | {$task->override_2} | {$task->override_3}]\n";
echo "Shares before: [{$task->override_share_1} | {$task->override_share_2} | {$task->override_share_3}]\n";

// Ensure a clear non-even PDE split for the live probe.
$task->override_share_1 = '70';
$task->override_share_2 = '30';
$task->override_share_3 = null;
$task->save();
$task->refresh();

echo "Shares after PDE write: [{$task->override_share_1} | {$task->override_share_2}]\n";

$legacy = AlgorithmTaskMapper::toLegacy($task->toArray() + [
    'project_id' => $task->part?->project_id,
    'project_name' => $task->part?->project?->name,
]);

echo 'Mapper Override_Share: ['.($legacy['Override_Share_1'] ?? 'null').' | '.($legacy['Override_Share_2'] ?? 'null')."]\n";

$controller = app(AlgoController::class);
$ref = new ReflectionClass($controller);
$call = static function (string $method, array $args) use ($ref, $controller) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($controller, $args);
};

$ov1 = trim((string) $task->override_1);
$ov2 = trim((string) $task->override_2);
$employees = collect([
    (object) ['id' => 101, 'first_name' => $ov1, 'last_name' => ''],
    (object) ['id' => 102, 'first_name' => $ov2, 'last_name' => ''],
]);

$plan = (float) $task->plan;
$targets = $call('buildHypermodeOverrideFairTargets', [$plan, $employees, $legacy]);
$expected = $call('splitPlanByPercents', [$plan, [70.0, 30.0]]);

echo 'Fair targets: '.json_encode($targets)." expected=".json_encode($expected)."\n";

if ($targets !== $expected) {
    fwrite(STDERR, "FAIL: targets do not match PDE 70/30\n");
    exit(1);
}

// Patch live algorithm cache assignmentDetails for this task to the fair split
// (simulates post-algo hours the histogram reads).
$cache = AlgorithmCache::get() ?? [];
$details = $cache['assignmentDetails'] ?? [];
$found = false;
$doc = $task->doc_supply
    ? \Carbon\Carbon::parse($task->doc_supply)->format('Y-m-d')
    : now()->format('Y-m-d');
$day2 = \Carbon\Carbon::parse($doc)->addWeekday()->format('Y-m-d');

$h1a = (int) floor($targets[0] / 2);
$h1b = (int) $targets[0] - $h1a;
$h2a = (int) floor($targets[1] / 2);
$h2b = (int) $targets[1] - $h2a;

$newAssignments = array_values(array_filter([
    $h1a > 0 ? ['employee' => $ov1, 'date' => $doc, 'hours' => $h1a] : null,
    $h1b > 0 ? ['employee' => $ov1, 'date' => $day2, 'hours' => $h1b] : null,
    $h2a > 0 ? ['employee' => $ov2, 'date' => $doc, 'hours' => $h2a] : null,
    $h2b > 0 ? ['employee' => $ov2, 'date' => $day2, 'hours' => $h2b] : null,
]));

foreach ($details as $i => $detail) {
    if ((int) ($detail['task_id'] ?? 0) === $taskId) {
        $details[$i]['assignments'] = $newAssignments;
        $details[$i]['mode'] = 'HYPERMODE_OVERRIDE_EVEN_SPLIT';
        $details[$i]['Plan'] = $plan;
        $found = true;
        break;
    }
}

if (! $found) {
    $details[] = [
        'task_id' => $taskId,
        'TaskName' => $task->task_name,
        'mode' => 'HYPERMODE_OVERRIDE_EVEN_SPLIT',
        'Plan' => $plan,
        'assignments' => $newAssignments,
    ];
}

$cache['assignmentDetails'] = $details;
AlgorithmCache::put($cache);

$built = HistogramFromAssignments::build(
    array_values(array_filter($details, fn ($d) => (int) ($d['task_id'] ?? 0) === $taskId)),
    collect([$taskId => $task]),
    Nrm::query()->get()->keyBy('id')
);

$hoursByEmp = [];
foreach ($built['bars'] as $bar) {
    $emp = (string) $bar['employee'];
    $hoursByEmp[$emp] = ($hoursByEmp[$emp] ?? 0) + (float) $bar['hours'];
}

echo 'Histogram bars: '.json_encode($built['bars'] ? array_map(static fn ($b) => [
    'id' => $b['id'],
    'employee' => $b['employee'],
    'hours' => $b['hours'],
    'start' => $b['start'],
    'end' => $b['end'],
], $built['bars']) : [])."\n";
echo 'Hours by emp: '.json_encode($hoursByEmp)."\n";

$ok = abs(($hoursByEmp[$ov1] ?? -1) - $targets[0]) < 0.01
    && abs(($hoursByEmp[$ov2] ?? -1) - $targets[1]) < 0.01;

if (! $ok) {
    fwrite(STDERR, "FAIL: histogram hours do not match PDE 70/30 targets\n");
    exit(1);
}

echo "PASS live PDE 70/30 reflected in histogram bars for task #{$taskId}\n";
exit(0);
