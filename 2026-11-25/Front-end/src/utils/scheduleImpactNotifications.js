/**
 * Surface instant override-split schedule impact from PDE/DL save responses.
 */
export function showScheduleImpactNotifications(toast, scheduleImpact) {
  if (!toast || !scheduleImpact || !scheduleImpact.applied) {
    return;
  }

  const warn = typeof toast.warning === 'function' ? toast.warning.bind(toast) : toast.info?.bind(toast);
  const taskCount = Number(scheduleImpact.task_count || 0);
  const lower = Array.isArray(scheduleImpact.displaced_lower) ? scheduleImpact.displaced_lower.length : 0;
  const higher = Array.isArray(scheduleImpact.displaced_higher) ? scheduleImpact.displaced_higher.length : 0;
  const unplaced = Array.isArray(scheduleImpact.unplaced) ? scheduleImpact.unplaced.length : 0;
  const warnings = Array.isArray(scheduleImpact.warnings) ? scheduleImpact.warnings.filter(Boolean) : [];

  if (taskCount > 0) {
    const bits = [
      `Applied allocation split to the plan for ${taskCount} task${taskCount === 1 ? '' : 's'}`,
    ];
    if (lower > 0) {
      bits.push(`trimmed ${lower} lower-priority booking${lower === 1 ? '' : 's'}`);
    }
    toast.success?.(`${bits.join(' — ')}.`);
  }

  if (higher > 0 || warnings.some((text) => /higher-priority/i.test(String(text)))) {
    const detail = warnings.find((text) => /higher-priority/i.test(String(text)))
      || `Higher-priority work was trimmed (${higher} booking${higher === 1 ? '' : 's'}).`;
    warn?.(detail);
  }

  warnings
    .filter((text) => !/higher-priority/i.test(String(text)))
    .slice(0, 3)
    .forEach((text) => warn?.(text));

  if (unplaced > 0 && !warnings.some((text) => /could not place/i.test(String(text)))) {
    warn?.(
      `${unplaced} assignee share${unplaced === 1 ? '' : 's'} could not fully fit in the supply→completion window.`,
    );
  }
}
