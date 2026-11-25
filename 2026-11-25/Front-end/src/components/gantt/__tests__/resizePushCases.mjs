/**
 * Resize grow fills empty / leftover AVA without pushing neighbors.
 * Run: npx --yes vite-node src/components/gantt/__tests__/resizePushCases.mjs
 */
import {
  evaluateResizeWithPush,
  getBarActiveDays,
  shiftBarScheduleByWorkdays,
} from '../histogramBarEdits.js';
import { buildDayColumns } from '../ganttUtils.js';
import { HISTOGRAM_DAY_PARTITIONS } from '../histogramLayout.js';

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function buildBar(id, taskId, hoursByDay, extra = {}) {
  const days = Object.keys(hoursByDay).sort();
  return {
    id,
    task_id: taskId,
    task_name: `Task ${taskId}`,
    employee: 'Alex',
    start: days[0],
    end: days.at(-1),
    hours_by_day: hoursByDay,
    hours: Object.values(hoursByDay).reduce((sum, h) => sum + h, 0),
    ...extra,
  };
}

const dayColumns = buildDayColumns('2026-07-01', '2026-07-31');

function layout(rowBars) {
  return {
    dayColumns,
    rowName: 'Alex',
    availabilityHours: {},
    employeeHolidays: {},
    employees: [],
    rowBars,
    sourceRowBars: rowBars,
  };
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

test('shiftBarScheduleByWorkdays +1 skips weekend', () => {
  const bar = buildBar('a', 1, { '2026-07-10': 8 });
  const next = shiftBarScheduleByWorkdays(bar, 1, layout([]));
  assert(next.start === '2026-07-13', `expected Monday, got ${next.start}`);
  assert(next.hours_by_day['2026-07-13'] === 8, 'hours preserved');
});

test('end-grow into empty gap does not push later task', () => {
  const early = buildBar('early', 1, {
    '2026-07-06': 8,
    '2026-07-07': 8,
  });
  const later = buildBar('later', 2, {
    '2026-07-09': 8,
    '2026-07-10': 8,
  });
  const ctx = layout([later]);
  const result = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-07-08',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );

  assert(result.resizedBar.end === '2026-07-08', `expected end Wed, got ${result.resizedBar.end}`);
  assert(result.pushedBars.length === 0, 'must not push across empty Wed');
});

test('end-grow into a longer gap does not push task after weekend', () => {
  const early = buildBar('early', 1, {
    '2026-07-06': 8,
    '2026-07-07': 8,
  });
  const afterGap = buildBar('gap', 3, {
    '2026-07-14': 8,
    '2026-07-15': 8,
  });
  const ctx = layout([afterGap]);
  const result = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-07-09',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );

  assert(result.resizedBar.end === '2026-07-09', `expected grow into gap, got ${result.resizedBar.end}`);
  assert(result.pushedBars.length === 0, `gap task must stay put, got ${result.pushedBars.length} pushes`);
});

test('half-day free on next bar day: fill leftover without pushing', () => {
  const early = buildBar('early', 1, {
    '2026-07-06': 4,
  });
  const later = buildBar('later', 2, {
    '2026-07-08': 4,
  });
  const ctx = layout([later]);

  const throughTue = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-07-07',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );
  assert(throughTue.pushedBars.length === 0, 'full empty Tue must not push');
  assert(throughTue.resizedBar.end === '2026-07-07', 'should end on Tue');

  const intoWedFull = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-07-08',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );
  assert(intoWedFull.pushedBars.length === 0, 'must use Wed leftover, not push');
  assert(intoWedFull.resizedBar.hours_by_day['2026-07-08'] === 4, 'cap to remaining 4h on Wed');
  assert(intoWedFull.resizedBar.end === '2026-07-08', 'still reaches Wed');
});

test('full next day occupied: stop at wall, do not push', () => {
  const early = buildBar('early', 1, { '2026-07-06': 8 });
  const later = buildBar('later', 2, { '2026-07-07': 8 });
  const ctx = layout([later]);
  const result = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-07-07',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );
  assert(result.pushedBars.length === 0, 'must not push a fully booked neighbor');
  assert(
    !getBarActiveDays(result.resizedBar).includes('2026-07-07'),
    'must not claim a fully booked day',
  );
  assert(result.resizedBar.end === '2026-07-06', `expected stop Mon, got ${result.resizedBar.end}`);
});

test('stacked same-day neighbor is not pushed when grow only fills empty days', () => {
  const green = buildBar('green', 1, {
    '2026-07-08': 4,
  });
  const stacked = buildBar('stacked', 2, {
    '2026-07-08': 4,
  });
  const afterWeekend = buildBar('later', 3, {
    '2026-07-13': 8,
    '2026-07-14': 8,
  });
  const ctx = layout([stacked, afterWeekend]);
  const result = evaluateResizeWithPush(
    green,
    'resize-end',
    '2026-07-10',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );

  assert(result.resizedBar.end === '2026-07-10', `expected grow into Thu/Fri, got ${result.resizedBar.end}`);
  assert(result.pushedBars.length === 0, `empty-day grow must not push, got ${result.pushedBars.length}`);
});

test('shrink does not push', () => {
  const early = buildBar('early', 1, {
    '2026-07-06': 8,
    '2026-07-07': 8,
    '2026-07-08': 8,
  });
  const later = buildBar('later', 2, {
    '2026-07-09': 8,
  });
  const ctx = layout([later]);
  const result = evaluateResizeWithPush(
    early,
    'resize-end',
    '2026-07-07',
    ctx,
    null,
    HISTOGRAM_DAY_PARTITIONS,
  );

  assert(result.pushedBars.length === 0, 'shrink must not push');
  assert(result.resizedBar.end === '2026-07-07', `expected shrunk end, got ${result.resizedBar.end}`);
});

test('adjacent partial then full claim still does not push', () => {
  const early = buildBar('early', 1, { '2026-07-06': 8 });
  const later = buildBar('later', 2, { '2026-07-07': 4 });
  const ctx = layout([later]);
  const half = evaluateResizeWithPush(early, 'resize-end', '2026-07-07', ctx, null, 2);
  const full = evaluateResizeWithPush(early, 'resize-end', '2026-07-07', ctx, null, 4);
  assert(half.pushedBars.length === 0, 'half must not push');
  assert(full.pushedBars.length === 0, 'full claim must cap leftover, not push');
  assert(full.resizedBar.hours_by_day['2026-07-07'] === 4, 'cap to 4h leftover');
});

if (process.exitCode) {
  console.error(`\n${passed} passed before failures`);
} else {
  console.log(`\nAll ${passed} tests passed`);
}
