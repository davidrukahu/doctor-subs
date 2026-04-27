/* WP-admin chrome — neutral, NOT WP-branded. Subdued left sidebar + top bar
   acting as the container. The plugin content area is what's designed. */

const { useState } = React;

// Minimal stethoscope — outline stroke, single color, minimal
function Stethoscope({ size = 22 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none"
         stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"
         aria-hidden="true" className="ds-mark">
      <path d="M4 3v5a4 4 0 0 0 8 0V3" />
      <path d="M4 3h2M10 3h2" />
      <path d="M8 12v3a5 5 0 0 0 5 5h0a5 5 0 0 0 5-5v-1.5" />
      <circle cx="18" cy="9" r="2.2" />
    </svg>
  );
}

function AdminSidebar() {
  // Subdued, compressed. Not recreating any specific brand — generic CMS-admin chrome.
  const items = [
    { label: 'Overview', icon: '▤' },
    { label: 'Orders', icon: '▢' },
    { label: 'Products', icon: '▧' },
    { label: 'Customers', icon: '◎' },
    { label: 'Subscriptions', icon: '∞' },
    { label: 'Doctor Subs', icon: '✚', active: true },
    { label: 'Analytics', icon: '◨' },
    { label: 'Settings', icon: '⚙' },
  ];
  return (
    <aside style={{
      width: 188,
      flex: '0 0 188px',
      background: 'oklch(22% 0.012 210)',
      color: 'oklch(80% 0.008 210)',
      fontSize: 12.5,
      padding: '14px 0',
      userSelect: 'none',
    }}>
      <div style={{ padding: '4px 16px 12px', fontSize: 10.5, letterSpacing: '0.12em',
                    textTransform: 'uppercase', color: 'oklch(58% 0.01 210)' }}>
        Admin
      </div>
      {items.map((it) => (
        <div key={it.label} style={{
          padding: '7px 16px',
          display: 'flex', alignItems: 'center', gap: 10,
          background: it.active ? 'oklch(30% 0.03 200)' : 'transparent',
          color: it.active ? 'oklch(95% 0.008 200)' : 'oklch(78% 0.008 210)',
          borderLeft: it.active ? '2px solid oklch(60% 0.07 195)' : '2px solid transparent',
        }}>
          <span style={{ width: 14, opacity: 0.7, textAlign: 'center', fontSize: 12 }}>{it.icon}</span>
          <span>{it.label}</span>
        </div>
      ))}
    </aside>
  );
}

function AdminTopbar() {
  return (
    <div style={{
      height: 34,
      background: 'oklch(16% 0.012 210)',
      color: 'oklch(75% 0.008 210)',
      fontSize: 12,
      display: 'flex',
      alignItems: 'center',
      padding: '0 14px',
      gap: 16,
      borderBottom: '1px solid oklch(10% 0.01 210)',
    }}>
      <span style={{ color: 'oklch(88% 0.01 210)', fontWeight: 500 }}>mercato · admin</span>
      <span style={{ opacity: 0.6 }}>plugins</span>
      <span style={{ opacity: 0.6 }}>updates (2)</span>
      <span style={{ flex: 1 }} />
      <span style={{ opacity: 0.6 }}>howdy, rita</span>
    </div>
  );
}

/* The full chrome shell. children = the plugin content area. */
function AdminShell({ children, width = 1240, height = 820 }) {
  return (
    <div className="ds-root" style={{
      width, height,
      background: 'var(--bg-0)',
      display: 'flex',
      flexDirection: 'column',
      overflow: 'hidden',
      fontFamily: 'var(--font-body)',
    }}>
      <AdminTopbar />
      <div style={{ display: 'flex', flex: 1, minHeight: 0 }}>
        <AdminSidebar />
        <main style={{
          flex: 1,
          overflow: 'auto',
          background: 'var(--bg-0)',
        }}>
          {children}
        </main>
      </div>
    </div>
  );
}

/* Page header used across plugin surfaces (Doctor Subs wordmark + tabs) */
function PluginHeader({ tab = 'dashboard', onTab, lastScanned = '2 hours ago', stale = false, showMeta = true }) {
  const tabs = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'history', label: 'Fix history' },
    { id: 'settings', label: 'Settings' },
  ];
  return (
    <div style={{
      padding: '28px 44px 0',
      borderBottom: '1px solid var(--line-0)',
      background: 'var(--bg-0)',
    }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 24 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <Stethoscope size={26} />
          <div>
            <div style={{ fontSize: 19, fontWeight: 500, letterSpacing: '-0.012em' }}>Doctor Subs</div>
            <div style={{ fontSize: 12, color: 'var(--ink-2)', marginTop: 2 }}>
              Find and fix broken WooCommerce subscriptions.
            </div>
          </div>
        </div>
        {showMeta && (
          <div style={{
            fontSize: 12.5, color: stale ? 'oklch(45% 0.08 75)' : 'var(--ink-2)',
            display: 'flex', alignItems: 'center', gap: 10,
            paddingTop: 8,
          }}>
            <span>Last scanned {lastScanned}</span>
            <span style={{ color: 'var(--line-1)' }}>·</span>
            <a href="#" style={{ display: 'inline-flex', alignItems: 'center', gap: 4,
                                   color: 'var(--accent-ink)', textDecorationColor: 'oklch(82% 0.03 195)' }}>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M21 12a9 9 0 1 1-3-6.7L21 8" /><path d="M21 3v5h-5" />
              </svg>
              {stale ? 'Refresh now' : 'Refresh'}
            </a>
          </div>
        )}
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 28, marginTop: 24 }}>
        {tabs.map(t => (
          <button key={t.id}
            onClick={() => onTab && onTab(t.id)}
            style={{
              fontSize: 13.5,
              padding: '10px 0',
              color: tab === t.id ? 'var(--ink-0)' : 'var(--ink-2)',
              borderBottom: tab === t.id ? '2px solid var(--accent)' : '2px solid transparent',
              fontWeight: tab === t.id ? 500 : 400,
              marginBottom: -1,
            }}>
            {t.label}
          </button>
        ))}
      </div>
    </div>
  );
}

Object.assign(window, { AdminShell, AdminSidebar, AdminTopbar, PluginHeader, Stethoscope });
