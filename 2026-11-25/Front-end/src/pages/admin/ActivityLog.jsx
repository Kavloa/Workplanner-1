import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import ActivityLogTable from '../../components/ActivityLogTable';
import DisplayDateInput from '../../components/DisplayDateInput';
import { TablePageSkeleton } from '../../components/PageSkeleton';
import { useToast } from '../../context/ToastContext';
import usePageTitle from '../../hooks/usePageTitle';
import { getApiErrorMessage } from '../../utils/apiErrors';

const pageOptions = [
  { value: '', label: 'All pages' },
  { value: 'pde', label: 'Project Data Entry' },
  { value: 'dl', label: 'DL Input Hours' },
  { value: 'Trainings', label: 'Trainings' },
  { value: 'histogram', label: 'Resource Histogram' },
];

function userLabel(user) {
  const name = [user.first_name, user.last_name].filter(Boolean).join(' ').trim();
  return name || user.login_name || `User #${user.id}`;
}

export default function ActivityLog() {
  usePageTitle('Activity Log');
  const toast = useToast();
  const [logs, setLogs] = useState([]);
  const [users, setUsers] = useState([]);
  const [pageFilter, setPageFilter] = useState('');
  const [userId, setUserId] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [search, setSearch] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, pages: 1, limit: 50 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;

    const loadUsers = async () => {
      try {
        const response = await api.get('/admin/users');
        if (!cancelled) {
          setUsers(response.data.data ?? []);
        }
      } catch {
        if (!cancelled) {
          setUsers([]);
        }
      }
    };

    loadUsers();

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    const handle = window.setTimeout(() => {
      const next = searchInput.trim();
      if (next === search) {
        return;
      }
      setSearch(next);
      setPage(1);
    }, 300);
    return () => window.clearTimeout(handle);
  }, [search, searchInput]);

  const loadLogs = useCallback(async () => {
    setLoading(true);
    try {
      const response = await api.get('/admin/activity-logs', {
        params: {
          area: pageFilter || undefined,
          user_id: userId || undefined,
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
          search: search || undefined,
          page,
          limit: 50,
        },
      });
      setLogs(response.data.data);
      setMeta(response.data.meta);
      setError('');
    } catch (err) {
      const message = getApiErrorMessage(err, 'Unable to load activity log.');
      setError(message);
      setLogs([]);
      toast.error(message);
    } finally {
      setLoading(false);
    }
  }, [dateFrom, dateTo, page, pageFilter, search, toast, userId]);

  useEffect(() => {
    loadLogs();
  }, [loadLogs]);

  const hasActiveFilters = Boolean(pageFilter || userId || dateFrom || dateTo || search);

  const selectedUserLabel = useMemo(() => {
    if (!userId) return '';
    const user = users.find((item) => String(item.id) === String(userId));
    return user ? userLabel(user) : `User #${userId}`;
  }, [userId, users]);

  const selectedPageLabel = useMemo(
    () => pageOptions.find((option) => option.value === pageFilter)?.label || 'All pages',
    [pageFilter],
  );

  const resetFilters = () => {
    setPageFilter('');
    setUserId('');
    setDateFrom('');
    setDateTo('');
    setSearch('');
    setSearchInput('');
    setPage(1);
  };

  if (loading && logs.length === 0 && !error && !hasActiveFilters) {
    return <TablePageSkeleton rows={10} cols={5} className="activity-log-page" />;
  }

  return (
    <section className="activity-log-page">
      <header className="page-header page-header-compact">
        <div className="page-heading page-heading-row">
          <div className="page-heading-copy">
            <h2>Activity Log</h2>
            <p className="activity-log-description">
              Audit trail of edits across Project Data Entry, DL hours, trainings, and the resource histogram.
            </p>
          </div>
          <div className="page-header-actions">
            <button type="button" className="btn btn-secondary btn-compact" onClick={loadLogs} disabled={loading}>
              {loading ? 'Refreshing…' : 'Refresh'}
            </button>
          </div>
        </div>
      </header>

      <div className="activity-log-toolbar card">
        <div className="activity-log-filters">
          <label className="activity-log-filter activity-log-filter--search">
            Search
            <input
              type="search"
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder="Summary, user, action…"
              aria-label="Search activity log"
            />
          </label>

          <label className="activity-log-filter">
            Employee
            <select
              value={userId}
              onChange={(event) => {
                setUserId(event.target.value);
                setPage(1);
              }}
            >
              <option value="">All employees</option>
              {users.map((user) => (
                <option key={user.id} value={user.id}>
                  {userLabel(user)}
                </option>
              ))}
            </select>
          </label>

          <label className="activity-log-filter">
            From
            <DisplayDateInput
              compact
              className="activity-log-date-input"
              value={dateFrom}
              placeholder="Any date"
              onChange={(value) => {
                setDateFrom(value);
                setPage(1);
              }}
            />
          </label>

          <label className="activity-log-filter">
            To
            <DisplayDateInput
              compact
              className="activity-log-date-input"
              value={dateTo}
              placeholder="Any date"
              onChange={(value) => {
                setDateTo(value);
                setPage(1);
              }}
            />
          </label>

          <label className="activity-log-filter">
            Page
            <select
              value={pageFilter}
              onChange={(event) => {
                setPageFilter(event.target.value);
                setPage(1);
              }}
            >
              {pageOptions.map((option) => (
                <option key={option.value || 'all'} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>
        </div>

        <div className="activity-log-toolbar-actions">
          <button
            type="button"
            className="btn btn-secondary btn-compact"
            onClick={resetFilters}
            disabled={!hasActiveFilters}
          >
            Clear filters
          </button>
        </div>
      </div>

      <div className="activity-log-meta muted">
        <span>
          {meta.total} {meta.total === 1 ? 'entry' : 'entries'}
          {meta.pages > 1 ? ` · Page ${page} of ${meta.pages}` : ''}
        </span>
        {pageFilter ? <span className="activity-log-meta-chip">{selectedPageLabel}</span> : null}
        {selectedUserLabel ? <span className="activity-log-meta-chip">{selectedUserLabel}</span> : null}
        {dateFrom || dateTo ? (
          <span className="activity-log-meta-chip">
            {dateFrom || '…'} → {dateTo || '…'}
          </span>
        ) : null}
        {search ? <span className="activity-log-meta-chip">“{search}”</span> : null}
      </div>

      {error && (
        <div className="alert alert-error" role="alert">
          {error}
        </div>
      )}

      <div className={`table-card admin-table-wrap activity-log-table-card${loading ? ' is-loading' : ''}`}>
        {loading && logs.length === 0 ? (
          <p className="muted activity-log-empty">Loading activity log…</p>
        ) : !loading && logs.length === 0 ? (
          <div className="activity-log-empty-state">
            <p className="muted">
              {hasActiveFilters
                ? 'No activity matched the current filters.'
                : 'No activity recorded yet.'}
            </p>
            {hasActiveFilters ? (
              <button type="button" className="btn btn-secondary btn-compact" onClick={resetFilters}>
                Clear filters
              </button>
            ) : null}
          </div>
        ) : (
          <ActivityLogTable
            logs={logs}
            loading={false}
            showPageColumn
            emptyMessage={hasActiveFilters
              ? 'No activity matched the current filters.'
              : 'No activity recorded yet.'}
          />
        )}
      </div>

      {meta.pages > 1 && (
        <div className="activity-log-pagination">
          <button
            type="button"
            className="btn btn-secondary btn-compact"
            disabled={page <= 1 || loading}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          >
            Previous
          </button>
          <span className="muted">
            Page {page} of {meta.pages} ({meta.total} entries)
          </span>
          <button
            type="button"
            className="btn btn-secondary btn-compact"
            disabled={page >= meta.pages || loading}
            onClick={() => setPage((current) => current + 1)}
          >
            Next
          </button>
        </div>
      )}

      <p className="muted activity-log-back-link">
        <Link to="/projects">Back to Project Data Entry</Link>
      </p>
    </section>
  );
}
