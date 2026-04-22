/* Surface 1: First-run landing
   States: default, scanning, zero-subs
*/

function FirstRun({ state = 'default' }) {
  // state: 'default' | 'scanning' | 'zero'
  const isScanning = state === 'scanning';
  const isZero = state === 'zero';

  return (
    <div>
      <PluginHeader tab="dashboard" showMeta={false} />
      <div style={{ padding: '84px 96px 72px', maxWidth: 880 }}>

        {/* Hero — one display moment */}
        {!isZero && !isScanning && (
          <>
            <div style={{ fontSize: 11.5, letterSpacing: '0.12em', textTransform: 'uppercase',
                          color: 'var(--ink-2)', marginBottom: 18 }}>
              First visit
            </div>
            <h1 className="display" style={{ fontSize: 'var(--t-hero)', lineHeight: 1.05,
                                              letterSpacing: '-0.02em', maxWidth: 720,
                                              color: 'var(--ink-0)' }}>
              Let’s check your subscriptions <span className="ital" style={{ color: 'var(--accent-ink)' }}>for problems</span>.
            </h1>
            <p style={{ marginTop: 22, fontSize: 16, color: 'var(--ink-1)',
                         maxWidth: 560, lineHeight: 1.6 }}>
              We’ll scan your active subscriptions for three common renewal failures.
              Takes about 30 seconds. Runs entirely on your server — nothing sent
              anywhere.
            </p>

            <div style={{ marginTop: 36, display: 'flex', alignItems: 'center', gap: 20 }}>
              <button className="btn btn-primary" style={{ padding: '12px 20px', fontSize: 14 }}>
                Scan my subscriptions
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M9 18l6-6-6-6" />
                </svg>
              </button>
              <a href="#" style={{ fontSize: 13.5, color: 'var(--ink-1)' }}>
                <span className="ital display" style={{ fontSize: 16 }}>or</span> configure settings first
              </a>
            </div>
          </>
        )}

        {isScanning && <ScanningBlock n={247} remaining={18} />}

        {isZero && (
          <>
            <div style={{ fontSize: 11.5, letterSpacing: '0.12em', textTransform: 'uppercase',
                          color: 'var(--ink-2)', marginBottom: 18 }}>
              Nothing to scan yet
            </div>
            <h1 className="display" style={{ fontSize: 44, lineHeight: 1.1, maxWidth: 640,
                                              letterSpacing: '-0.02em' }}>
              You don’t have any active subscriptions <span className="ital" style={{ color: 'var(--ink-2)' }}>to scan yet</span>.
            </h1>
            <p style={{ marginTop: 20, fontSize: 15, color: 'var(--ink-1)', maxWidth: 520 }}>
              Doctor Subs will start watching once you do. You can leave this tab open —
              or come back when your first customer subscribes.
            </p>
          </>
        )}

        {/* "What this detects" */}
        <div style={{ marginTop: 72, borderTop: '1px solid var(--line-0)', paddingTop: 40 }}>
          <div style={{ display: 'flex', alignItems: 'baseline', gap: 14, marginBottom: 24 }}>
            <span className="mono" style={{ fontSize: 11, color: 'var(--ink-3)' }}>03</span>
            <h2 style={{ fontSize: 13, fontWeight: 500, letterSpacing: '0.08em',
                          textTransform: 'uppercase', color: 'var(--ink-1)' }}>
              What this detects
            </h2>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 0 }}>
            <DetectRow
              n="01"
              name="Ghost subscriptions"
              desc="Active subscriptions that won’t renew because the payment didn’t get scheduled."
            />
            <DetectRow
              n="02"
              name="Stuck on-hold"
              desc="Payment went through, but the subscription never switched back to active."
            />
            <DetectRow
              n="03"
              name="Repeated payment failures"
              desc="Something has been failing to process a payment for a while."
              last
            />
          </div>
        </div>
      </div>
    </div>
  );
}

function DetectRow({ n, name, desc, last }) {
  return (
    <div style={{
      display: 'grid',
      gridTemplateColumns: '48px 220px 1fr',
      gap: 24,
      padding: '20px 0',
      borderBottom: last ? 'none' : '1px solid var(--line-0)',
      alignItems: 'baseline',
    }}>
      <span className="mono" style={{ fontSize: 11, color: 'var(--ink-3)' }}>{n}</span>
      <span style={{ fontSize: 15.5, fontWeight: 500, letterSpacing: '-0.005em' }}>{name}</span>
      <span style={{ fontSize: 14, color: 'var(--ink-1)', lineHeight: 1.55, maxWidth: 560 }}>{desc}</span>
    </div>
  );
}

function ScanningBlock({ n = 247, remaining = 18 }) {
  // A quiet, honest progress indicator — no spinner, a thin deterministic bar.
  const pct = ((n - (remaining * (n / 30))) / n) * 100;
  return (
    <>
      <div style={{ fontSize: 11.5, letterSpacing: '0.12em', textTransform: 'uppercase',
                    color: 'var(--ink-2)', marginBottom: 18 }}>
        Scanning
      </div>
      <h1 className="display" style={{ fontSize: 48, lineHeight: 1.1, letterSpacing: '-0.02em' }}>
        Scanning your subscriptions<span style={{ color: 'var(--accent-ink)' }}>…</span>
      </h1>
      <p style={{ marginTop: 18, fontSize: 15, color: 'var(--ink-1)' }}>
        Checking <span className="mono tnum" style={{ color: 'var(--ink-0)' }}>{n}</span> active subs —
        about <span className="mono tnum" style={{ color: 'var(--ink-0)' }}>{remaining}</span> seconds left.
      </p>

      <div style={{ marginTop: 34, maxWidth: 520 }}>
        <div style={{ height: 2, background: 'var(--line-0)', position: 'relative', borderRadius: 2 }}>
          <div style={{ position: 'absolute', left: 0, top: 0, bottom: 0,
                         width: `${Math.max(8, Math.min(96, pct))}%`,
                         background: 'var(--accent)', borderRadius: 2,
                         transition: 'width 400ms cubic-bezier(0.2, 0.8, 0.2, 1)' }} />
        </div>
        <div style={{ marginTop: 14, display: 'flex', justifyContent: 'space-between',
                       fontSize: 12, color: 'var(--ink-2)' }}>
          <span className="mono tnum">checking sub_00{(n - remaining * 8).toString().padStart(3, '0')}</span>
          <a href="#" style={{ color: 'var(--ink-2)' }}>Cancel</a>
        </div>
      </div>
    </>
  );
}

Object.assign(window, { FirstRun });
