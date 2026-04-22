/* Surface 2: Dashboard
   States: mixed (default), all-healthy, scan-failed, scanning-refresh
*/

const SUBS = [
  { id: 4812, name: 'Sarah Mendez', rule: 'ghost',     reason: 'Next payment didn’t get rescheduled after the March 15 renewal.', bucket: 'broken', amount: '$29.00', since: 'Mar 15' },
  { id: 5104, name: 'Marcus Abernathy', rule: 'onhold', reason: 'Charged $29 in Stripe on Apr 8 but subscription stayed on-hold.', bucket: 'broken', amount: '$29.00', since: 'Apr 8' },
  { id: 3918, name: 'Anya Volkova', rule: 'repfail',  reason: '4 failed renewal attempts since Mar 22 — looks like a gateway issue.', bucket: 'broken', amount: '$49.00', since: 'Mar 22' },
  { id: 6230, name: 'Jin-ho Park', rule: 'ghost',     reason: 'Renewal event for Apr 19 missing from the scheduler.', bucket: 'risk', amount: '$15.00', since: 'Apr 19' },
  { id: 6477, name: 'Beatrice Owoyemi', rule: 'repfail', reason: '2 failed attempts in the last 36 hours — card may be declining.', bucket: 'risk', amount: '$79.00', since: 'Apr 20' },
  { id: 2201, name: 'Theo Lindqvist', rule: 'onhold', reason: 'Last payment succeeded Apr 12, status has been on-hold since.', bucket: 'risk', amount: '$12.00', since: 'Apr 12' },
];

const RULE_META = {
  ghost:   { label: 'Ghost',          pill: 'pill-broken',  dot: 'dot-broken' },
  onhold:  { label: 'Stuck on-hold',  pill: 'pill-risk',    dot: 'dot-risk' },
  repfail: { label: 'Repeated fails', pill: 'pill-risk',    dot: 'dot-risk' },
};

function Counter({ label, n, state, active, onClick }) {
  const color = state === 'healthy' ? 'var(--healthy)'
              : state === 'risk'    ? 'var(--risk)'
              : state === 'broken'  ? 'var(--broken)'
              : 'var(--ink-3)';
  return (
    <button onClick={onClick}
      style={{
        textAlign: 'left',
        padding: '22px 24px 22px 26px',
        borderRadius: 0,
        borderLeft: `2px solid ${active ? color : 'var(--line-1)'}`,
        background: active ? 'var(--bg-1)' : 'transparent',
        flex: 1,
        transition: 'background 160ms ease, border-color 160ms ease',
        cursor: 'pointer',
        minWidth: 0,
      }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <span className={`dot dot-${state}`} />
        <span style={{ fontSize: 11, letterSpacing: '0.1em', textTransform: 'uppercase',
                        color: 'var(--ink-2)', fontWeight: 500 }}>{label}</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 10 }}>
        <span className="display tnum" style={{
          fontSize: 62, lineHeight: 1, letterSpacing: '-0.03em',
          color: n === 0 && state !== 'healthy' ? 'var(--ink-3)' : 'var(--ink-0)',
        }}>{n}</span>
        <span style={{ fontSize: 12, color: 'var(--ink-2)' }}>
          {state === 'healthy' && 'no problems'}
          {state === 'risk' && 'might need attention'}
          {state === 'broken' && 'needs you now'}
        </span>
      </div>
    </button>
  );
}

function Dashboard({ state = 'mixed', onRowClick }) {
  const [filter, setFilter] = React.useState('all');

  const counts = React.useMemo(() => {
    if (state === 'healthy') return { healthy: 241, risk: 0, broken: 0 };
    return { healthy: 238, risk: 3, broken: 3 };
  }, [state]);

  const visible = React.useMemo(() => {
    if (filter === 'broken') return SUBS.filter(s => s.bucket === 'broken');
    if (filter === 'risk') return SUBS.filter(s => s.bucket === 'risk');
    return SUBS;
  }, [filter]);

  const isRefreshing = state === 'refreshing';
  const isFailed = state === 'failed';
  const isHealthy = state === 'healthy';

  return (
    <div>
      <PluginHeader tab="dashboard" lastScanned="2 hours ago" stale={false} />

      <div style={{ padding: '28px 44px 60px' }}>

        {isFailed && (
          <div style={{
            marginBottom: 24, padding: '14px 18px',
            borderLeft: '2px solid var(--risk)',
            background: 'var(--risk-weak)',
            fontSize: 13.5, color: 'oklch(32% 0.06 75)',
            display: 'flex', alignItems: 'center', gap: 16,
          }}>
            <span style={{ flex: 1 }}>
              The last scan didn’t complete. Nothing was changed.
            </span>
            <a href="#" style={{ color: 'inherit', fontWeight: 500 }}>Try again</a>
            <span style={{ color: 'oklch(75% 0.04 75)' }}>·</span>
            <a href="#" style={{ color: 'inherit' }}>View logs</a>
          </div>
        )}

        {/* Counters row */}
        <div style={{
          display: 'flex',
          border: '1px solid var(--line-0)',
          borderRadius: 0,
          background: 'var(--bg-0)',
          opacity: isRefreshing ? 0.55 : 1,
          transition: 'opacity 200ms ease',
        }}>
          <Counter label="Healthy"  n={counts.healthy} state="healthy"
                   active={filter === 'all' && !isHealthy ? false : filter === 'healthy'}
                   onClick={() => setFilter(filter === 'healthy' ? 'all' : 'healthy')} />
          <div style={{ width: 1, background: 'var(--line-0)' }} />
          <Counter label="At risk"  n={counts.risk} state="risk"
                   active={filter === 'risk'}
                   onClick={() => setFilter(filter === 'risk' ? 'all' : 'risk')} />
          <div style={{ width: 1, background: 'var(--line-0)' }} />
          <Counter label="Broken"   n={counts.broken} state="broken"
                   active={filter === 'broken'}
                   onClick={() => setFilter(filter === 'broken' ? 'all' : 'broken')} />
        </div>

        {/* All-healthy empty */}
        {isHealthy ? (
          <div style={{ marginTop: 80, textAlign: 'left', maxWidth: 520 }}>
            <div style={{ fontSize: 11.5, letterSpacing: '0.12em', textTransform: 'uppercase',
                          color: 'var(--ink-2)', marginBottom: 14 }}>
              <span className="dot dot-healthy" style={{ marginRight: 8, verticalAlign: 'middle' }} />
              All clear
            </div>
            <h2 className="display" style={{ fontSize: 40, letterSpacing: '-0.02em', lineHeight: 1.1 }}>
              Everything looks good.
            </h2>
            <p style={{ marginTop: 14, fontSize: 14.5, color: 'var(--ink-1)' }}>
              We’ll keep watching. Last checked 2 hours ago.
            </p>
          </div>
        ) : (
          <>
            {/* Needs attention table */}
            <div style={{ marginTop: 44 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between',
                            marginBottom: 14 }}>
                <div style={{ display: 'flex', alignItems: 'baseline', gap: 14 }}>
                  <h2 style={{ fontSize: 15, fontWeight: 500 }}>Needs attention</h2>
                  <span style={{ fontSize: 12, color: 'var(--ink-2)' }}>
                    {filter === 'all' && 'showing all broken and at-risk'}
                    {filter === 'broken' && `filtering to ${SUBS.filter(s => s.bucket === 'broken').length} broken`}
                    {filter === 'risk'   && `filtering to ${SUBS.filter(s => s.bucket === 'risk').length} at-risk`}
                  </span>
                </div>
                {filter !== 'all' && (
                  <button onClick={() => setFilter('all')}
                    style={{ fontSize: 12, color: 'var(--ink-2)' }}>clear filter</button>
                )}
              </div>

              <div style={{ border: '1px solid var(--line-0)', background: 'var(--bg-0)' }}>
                <table className="ds-table">
                  <thead>
                    <tr>
                      <th style={{ width: 40 }}></th>
                      <th>Customer</th>
                      <th>Subscription</th>
                      <th>Issue</th>
                      <th>Reason</th>
                      <th style={{ textAlign: 'right' }}></th>
                    </tr>
                  </thead>
                  <tbody>
                    {visible.map(s => (
                      <tr key={s.id} className="clickable" onClick={() => onRowClick && onRowClick(s)}>
                        <td><span className={`dot ${RULE_META[s.rule].dot}`} /></td>
                        <td style={{ fontWeight: 500 }}>{s.name}</td>
                        <td>
                          <span className="mono" style={{ color: 'var(--ink-1)' }}>#{s.id}</span>
                          <span style={{ color: 'var(--ink-3)', marginLeft: 10, fontSize: 12 }}>{s.amount}</span>
                        </td>
                        <td><span className={`pill ${RULE_META[s.rule].pill}`}>{RULE_META[s.rule].label}</span></td>
                        <td style={{ color: 'var(--ink-1)', maxWidth: 340 }}>
                          <div style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                            {s.reason}
                          </div>
                        </td>
                        <td style={{ textAlign: 'right' }}>
                          <button className="btn btn-outline btn-sm"
                                  onClick={(e) => { e.stopPropagation(); onRowClick && onRowClick(s); }}>
                            Fix
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

Object.assign(window, { Dashboard, SUBS, RULE_META });
