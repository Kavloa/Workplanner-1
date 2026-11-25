<?php
/**
 * Compare PDE override shares (via mapper) to algorithm cache hours (histogram source).
 * Run: php scripts/check-live-override-split-vs-histogram.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\AlgoController;
use App\Models\ProjectTask;
use App\Support\AlgorithmCache;
use App\Support\AlgorithmTaskMapper;

$tasks = ProjectTask::query()
    ->whereNotNull('override_1')->where('override_1', '!=', '')
    ->whereNotNull('override_2')->where('override_2', '!=', '')
    ->where(function ($q) {
        $q->whereNull('override_3')->orWhere('override_3', '');
    })
    ->whereNotNull('override_share_1')->where('override_share_1', '!=', '')
    ->whereNotNull('override_share_2')->where('override_share_2', '!=', '')
    ->where(function ($q) {
        $q->where('done', false)->orWhereNull('done');
    })
    ->orderByDesc('id')
    ->limit(12)
    ->get();

echo 'Tasks with 2-override + shares: '.$tasks->count().PHP_EOL;

$cache = AlgorithmCache::get() ?? [];
$details = collect($cache['assignmentDetails'] ?? []);

$controller = app(AlgoController::class);
$ref = new ReflectionClass($controller);
$call = static function (string $method, array $args) use ($ref, $controller) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($controller, $args);
};

$mismatches = 0;
$checked = 0;

foreach ($tasks as $t) {
    $legacy = AlgorithmTaskMapper::toLegacy($t->toArray());
    $detail = $details->first(fn ($d) => (int) ($d['task_id'] ?? 0) === (int) $t->id);

    $hours = [];
    if (is_array($detail)) {
        foreach (($detail['assignments'] ?? []) as $a) {
            $e = trim((string) ($a['employee'] ?? ''));
            if ($e === '') {
                continue;
            }
            $hours[$e] = ($hours[$e] ?? 0) + (float) ($a['hours'] ?? 0);
        }
    }

    $share1 = (float) ($legacy['Override_Share_1'] ?? 0);
    $share2 = (float) ($legacy['Override_Share_2'] ?? 0);
    $plan = is_numeric($t->plan) ? (float) $t->plan : 0.0;
    $expected = $plan > 0 && ($share1 + $share2) > 0
        ? $call('splitPlanByPercents', [$plan, [$share1, $share2]])
        : null;

    $ov1 = trim((string) $t->override_1);
    $ov2 = trim((string) $t->override_2);
    $h1 = $hours[$ov1] ?? null;
    $h2 = $hours[$ov2] ?? null;

    $okMapper = ($legacy['Override_Share_1'] ?? null) === (string) $t->override_share_1
        && ($legacy['Override_Share_2'] ?? null) === (string) $t->override_share_2;

    $cacheOk = null;
    if ($expected !== null && $h1 !== null && $h2 !== null) {
        $cacheOk = abs($h1 - $expected[0]) <= 1.0 && abs($h2 - $expected[1]) <= 1.0;
        $checked++;
        if (! $cacheOk) {
            $mismatches++;
        }
    }

    echo json_encode([
        'id' => $t->id,
        'task' => $t->task_name,
        'plan' => $plan,
        'pde_shares' => [$t->override_share_1, $t->override_share_2],
        'mapper_ok' => $okMapper,
        'mapper_shares' => [$legacy['Override_Share_1'] ?? null, $legacy['Override_Share_2'] ?? null],
        'expected_hours' => $expected,
        'cache_hours' => $hours,
        'mode' => is_array($detail) ? ($detail['mode'] ?? null) : null,
        'cache_matches_shares' => $cacheOk,
        'in_cache' => $detail !== null,
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
}

echo PHP_EOL."Checked cache hour splits: {$checked}; mismatches (pre-re-run stale possible): {$mismatches}".PHP_EOL;
echo 'NOTE: cache may still be even-split until algorithm is re-run after mapper fix.'.PHP_EOL;
exit(0);
