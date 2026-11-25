/**
 * Live-style resize push checks matching the reported half-day / gap behavior.
 * Run: npx --yes vite-node src/components/gantt/__tests__/liveResizePushCheck.mjs
 */
import { evaluateResizeWithPush, getBarActiveDays } from '../histogramBarEdits.js';
import { buildDayColumns } from '../ganttUtils.js';
import { HISTOGRAM_DAY_PARTITIONS } from '../histogramLayout.js';

const dayColumns = buildDayColumns('2026-11-20', '2026-12-15');

function buildBar(id, taskId, hoursByDay) {
  const days = Object.keys(hoursByDay).sort();
  return {
    id,
    task_id: taskId,
    task_name: id,
    employee: 'Alex',
    start: days[0],
    end: days.at(-1),
    hours_by_day: hoursByDay,
    hours: Object.values(hoursByDay).reduce((sum, h) => sum + h, 0),
  };
}

function layout(rowBars, availabilityHours = {}) {
  return {
    dayColumns,
    rowName: 'Alex',
    availabilityHours,
    employeeHolidays: {},
    employees: [],
    rowBars,
    sourceRowBars: rowBars,
  };
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const ava = {};
for (const day of dayColumns) {
  const d = new Date(`${day}T12:00:00`);
  if (d.getDay() !== 0 && d.getDay() !== 6) {
    ava[day] = 8;
  }
}

let passed = 0;
function test(name, fn) {
  try {
    fn();
    passed += 1;
    console.log(`PASS  ${name}`);
  } catch (err) {
    console.error(`FAIL  ${name}`);
    console.error(`      ${err.message}`);
    process.exitCode = 1;
  }
}

// Screenshot pattern: ends Wed 25 half/stack, empty Thu-Fri, next Mon 30.
test('grow Wed→Fri into empty gap before Mon task: no push', () => {
  const early = buildBar('early', 1, { '2026-11-25': 4 });
  const later = buildBar('later', 2, { '2026-11-30': 8, '2026-12-01': 8 });
  const stacked = buildBar('stack', 3, { '2026-11-25': 4 });
  const ctx = layout([later, stacked], { Alex: ava });
  const result = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-11-27',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );
  assert(result.pushedBars.length === 0, `pushed ${result.pushedBars.length}`);
  assert(getBarActiveDays(result.resizedBar).includes('2026-11-27'), 'should fill Fri');
});

test('1.5d gap then half-occupied Wed: full claim uses leftover, no push', () => {
  const early = buildBar('early', 1, { '2026-11-23': 4 }); // Mon half
  const later = buildBar('later', 2, { '2026-11-25': 4 }); // Wed half
  const ctx = layout([later], { Alex: ava });
  const throughTue = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-11-24',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );
  assert(throughTue.pushedBars.length === 0, 'Tue empty must not push');

  const intoWed = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-11-25',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );
  assert(intoWed.pushedBars.length === 0, 'Wed leftover must not push');
  assert(intoWed.resizedBar.hours_by_day['2026-11-25'] === 4, 'cap to 4h leftover');
});

test('fully booked next day stops without pushing', () => {
  const early = buildBar('early', 1, { '2026-11-23': 8 });
  const later = buildBar('later', 2, { '2026-11-24': 8 });
  const ctx = layout([later], { Alex: ava });
  const result = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-11-24',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );
  assert(result.pushedBars.length === 0, `must not push, got ${result.pushedBars.length}`);
  assert(!getBarActiveDays(result.resizedBar).includes('2026-11-24'), 'must not claim full day');
});

if (!process.exitCode) {
  console.log(`\nAll ${passed} live checks passed`);
}
