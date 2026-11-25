/**
 * Runnable cases for whole task-bar drag/drop (workday shifting across weekends).
 * Run: npx --yes vite-node src/components/gantt/__tests__/dragBarCases.mjs
 */
import {
  canAddTaskOnRowDay,
  dragBarToSnapDay,
  evaluateBarDrag,
  getBarActiveDays,
  shiftBarByDayDelta,
} from '../histogramBarEdits.js';
import { buildDayColumns } from '../ganttUtils.js';
import { HISTOGRAM_MAX_TASKS_PER_DAY } from '../histogramLayout.js';

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function buildBar(hoursByDay, extra = {}) {
  const days = Object.keys(hoursByDay).sort();
  return {
    id: 'bar-1',
    task_id: 10,
    employee: 'Alex',
    start: days[0],
    end: days.at(-1),
    hours_by_day: hoursByDay,
    hours: Object.values(hoursByDay).reduce((sum, h) => sum + h, 0),
    ...extra,
  };
}

const dayColumns = buildDayColumns('2026-07-01', '2026-07-31');
const ctx = {
  dayColumns,
  rowName: 'Alex',
  availabilityHours: {},
  employeeHolidays: {},
  employees: [],
};

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

test('drag +1 workday mid-week shifts schedule', () => {
  const bar = buildBar({
    '2026-07-07': 8,
    '2026-07-08': 8,
    '2026-07-09': 4,
  });
  // Bar centre is Wed 07-08; move centre to Thu 07-09 → +1 workday.
  const next = dragBarToSnapDay(bar, '2026-07-09', ctx, {
    anchorSnapDay: '2026-07-07',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
  });
  assert(next.start === '2026-07-08', `expected start Wed, got ${next.start}`);
  assert(next.end === '2026-07-10', `expected end Fri, got ${next.end}`);
  assert(next.hours_by_day['2026-07-08'] === 8, 'Tue hours should land on Wed');
  assert(next.hours_by_day['2026-07-10'] === 4, 'Thu hours should land on Fri');
});

test('drag across weekend Fri→Mon does not stick on Saturday', () => {
  const bar = buildBar({
    '2026-07-09': 8,
    '2026-07-10': 8,
  });
  // Pointer on Saturday with Friday anchor → snap to Monday, +1 workday.
  const next = dragBarToSnapDay(bar, '2026-07-11', ctx, {
    anchorSnapDay: '2026-07-10',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
  });
  assert(next.start === '2026-07-10', `expected start Fri, got ${next.start}`);
  assert(next.end === '2026-07-13', `expected end Mon, got ${next.end}`);
  assert(next.hours_by_day['2026-07-10'] === 8, 'Thu→Fri');
  assert(next.hours_by_day['2026-07-13'] === 8, 'Fri→Mon');
  assert(!next.hours_by_day['2026-07-11'], 'must not place hours on Saturday');
  assert(!next.hours_by_day['2026-07-12'], 'must not place hours on Sunday');
});

test('drag onto Sunday still advances one workday to Monday', () => {
  const bar = buildBar({
    '2026-07-10': 8,
  });
  const next = dragBarToSnapDay(bar, '2026-07-12', ctx, {
    anchorSnapDay: '2026-07-10',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
  });
  assert(next.start === '2026-07-13', `expected Monday, got ${next.start}`);
  assert(next.hours_by_day['2026-07-13'] === 8, 'Friday should move to Monday');
});

test('drag back across weekend Mon→Fri', () => {
  const bar = buildBar({
    '2026-07-13': 8,
    '2026-07-14': 8,
  });
  // Bar centre is Tue 07-14; move centre to Fri 07-10 → −2 workdays.
  const next = dragBarToSnapDay(bar, '2026-07-10', ctx, {
    anchorSnapDay: '2026-07-13',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
  });
  assert(next.start === '2026-07-09', `expected Thu, got ${next.start}`);
  assert(next.end === '2026-07-10', `expected Friday, got ${next.end}`);
  assert(next.hours_by_day['2026-07-09'] === 8, 'Mon→Thu');
  assert(next.hours_by_day['2026-07-10'] === 8, 'Tue→Fri');
});

test('shiftBarByDayDelta +1 skips weekend', () => {
  const bar = buildBar({
    '2026-07-10': 8,
  });
  const next = shiftBarByDayDelta(bar, 1, ctx);
  assert(next.start === '2026-07-13', `expected Monday after Friday, got ${next.start}`);
});

test('multi-day bar preserves hour shape across weekend', () => {
  const bar = buildBar({
    '2026-07-08': 2,
    '2026-07-09': 4,
    '2026-07-10': 8,
  });
  // Bar centre is Thu 07-09; move centre to Mon 07-13 → +2 workdays.
  const next = dragBarToSnapDay(bar, '2026-07-13', ctx, {
    anchorSnapDay: '2026-07-10',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
  });
  const days = getBarActiveDays(next);
  assert(days.join(',') === '2026-07-10,2026-07-13,2026-07-14', `got ${days.join(',')}`);
  assert(next.hours_by_day['2026-07-10'] === 2, '2h shape preserved');
  assert(next.hours_by_day['2026-07-13'] === 4, '4h shape preserved');
  assert(next.hours_by_day['2026-07-14'] === 8, '8h shape preserved');
});

test('drag across weekend moves all fragments not just grabbed segment', () => {
  const bar = buildBar({
    '2026-07-10': 4,
    '2026-07-13': 8,
    '2026-07-14': 8,
  });
  const monIndex = dayColumns.indexOf('2026-07-13');
  const next = dragBarToSnapDay(bar, '2026-07-15', ctx, {
    anchorSnapDay: '2026-07-13',
    anchorBarCenterDay: '2026-07-13',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
    segment: { startIndex: monIndex, endIndex: monIndex + 2 },
  });
  assert(!next.hours_by_day['2026-07-10'], 'Friday fragment should move with the bar');
  assert(!next.hours_by_day['2026-07-13'], 'Monday should not stay behind');
  assert(next.hours_by_day['2026-07-14'] === 4, 'Friday 4h should land on Tuesday');
  assert(next.hours_by_day['2026-07-15'] === 8, 'Monday 8h should land on Wednesday');
  assert(next.hours_by_day['2026-07-16'] === 8, 'Tuesday 8h should land on Thursday');
});

test('no-op when pointer stays on same workday', () => {
  const bar = buildBar({ '2026-07-08': 8 });
  const next = dragBarToSnapDay(bar, '2026-07-08', ctx, {
    anchorSnapDay: '2026-07-08',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
  });
  assert(next === bar || next.start === bar.start, 'expected unchanged schedule');
});

test('drag onto partially occupied day when spare capacity remains', () => {
  const other = buildBar({ '2026-07-08': 4 }, {
    id: 'bar-2',
    task_id: 99,
    task_name: 'Survey',
    employee: 'Alex',
  });
  const bar = buildBar({ '2026-07-09': 4 });
  const ctxWithRowBars = {
    ...ctx,
    rowBars: [other, bar],
    sourceRowBars: [other, bar],
  };
  const next = dragBarToSnapDay(bar, '2026-07-08', ctxWithRowBars, {
    anchorSnapDay: '2026-07-09',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
    targetRowBars: [other, bar],
    sourceRowBars: [other, bar],
  });
  assert(next.start === '2026-07-08', `expected start 2026-07-08, got ${next.start}`);
  assert(next.hours_by_day['2026-07-08'] === 4, 'should share the partially booked day');
  assert(!next.hours_by_day['2026-07-09'], 'should leave the previous day');
});

test('drag onto full day pushes the competing bar forward', () => {
  const other = buildBar({ '2026-07-08': 8 }, {
    id: 'bar-2',
    task_id: 99,
    task_name: 'Survey',
    employee: 'Alex',
  });
  const bar = buildBar({ '2026-07-09': 8 });
  const ctxWithRowBars = {
    ...ctx,
    rowBars: [other, bar],
    sourceRowBars: [other, bar],
  };
  const result = evaluateBarDrag(bar, '2026-07-08', ctxWithRowBars, {
    anchorSnapDay: '2026-07-09',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
    targetRowBars: [other, bar],
    sourceRowBars: [other, bar],
  });
  assert(!result.blocked, 'expected drag onto full day to succeed via push');
  assert(result.commitBar?.hours_by_day?.['2026-07-08'] === 8, 'mover should land on Jul 8');
  assert(result.pushedBars?.length === 1, `expected 1 pushed bar, got ${result.pushedBars?.length}`);
  assert(
    result.pushedBars[0].nextBar.hours_by_day['2026-07-09'] === 8,
    'competitor should move to Jul 9',
  );
});

test(`max ${HISTOGRAM_MAX_TASKS_PER_DAY} distinct tasks per employee day (current/future months only)`, () => {
  const now = new Date(2026, 10, 23); // Nov 23, 2026
  const futureDay = '2026-11-10';
  const pastDay = '2026-07-08';
  const barsFuture = [1, 2, 3].map((n) => buildBar({ [futureDay]: 2 }, {
    id: `bar-${n}`,
    task_id: 100 + n,
    task_name: `Task ${n}`,
    employee: 'Alex',
  }));
  const barsPast = [1, 2, 3].map((n) => buildBar({ [pastDay]: 2 }, {
    id: `bar-past-${n}`,
    task_id: 200 + n,
    task_name: `Past ${n}`,
    employee: 'Alex',
  }));
  const fourth = buildBar({ '2026-11-11': 2 }, {
    id: 'bar-4',
    task_id: 104,
    task_name: 'Task 4',
    employee: 'Alex',
  });
  assert(
    canAddTaskOnRowDay(fourth, futureDay, barsFuture, now) === false,
    '4th distinct task must be blocked in current/future month',
  );
  assert(
    canAddTaskOnRowDay(barsFuture[0], futureDay, barsFuture, now) === true,
    'existing task on that day remains allowed',
  );
  assert(
    canAddTaskOnRowDay(fourth, pastDay, barsPast, now) === true,
    'past months stay uncapped',
  );
});

test('drag onto shared day caps hours and spills overflow on high-capacity days', () => {
  const other = buildBar({ '2026-07-08': 8 }, {
    id: 'bar-2',
    task_id: 99,
    task_name: '8.7 - Services',
    employee: 'Alex',
  });
  const bar = buildBar({ '2026-07-09': 8 }, {
    task_id: 86,
    task_name: '8.6 - Drainage',
  });
  const ava = {
    Alex: {
      '2026-07-08': 14,
      '2026-07-09': 14,
      '2026-07-10': 14,
    },
  };
  const ctxWithRowBars = {
    ...ctx,
    availabilityHours: ava,
    rowBars: [other, bar],
    sourceRowBars: [other, bar],
  };
  const next = dragBarToSnapDay(bar, '2026-07-08', ctxWithRowBars, {
    anchorSnapDay: '2026-07-09',
    sourceRowName: 'Alex',
    targetRowName: 'Alex',
    targetRowBars: [other, bar],
    sourceRowBars: [other, bar],
  });
  assert(next.hours_by_day['2026-07-08'] === 6, `expected 6h on shared day, got ${next.hours_by_day['2026-07-08']}`);
  assert(next.hours_by_day['2026-07-09'] === 2, `expected 2h spill on next day, got ${next.hours_by_day['2026-07-09']}`);
});

console.log(`\n${passed} passed`);
