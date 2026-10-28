{{--
    Gaya panel mengikuti desain SIMPEG (kepegawaian_new): nila sebagai warna
    utama, slate sebagai netral, kartu rounded-2xl. Disuntik lewat render hook
    HEAD_END supaya tidak perlu build Tailwind.
--}}
<style>
    :root {
        --pk-bg: #f8fafc;              /* slate-50 */
        --pk-surface: #ffffff;
        --pk-border: rgb(226 232 240 / .8); /* slate-200/80 */
        --pk-line: #e2e8f0;
        --pk-text: #0f172a;            /* slate-900 */
        --pk-body: #475569;            /* slate-600 */
        --pk-muted: #64748b;           /* slate-500 */
        --pk-faint: #94a3b8;           /* slate-400 */
        --pk-hover: #f1f5f9;           /* slate-100 */
        --pk-track: #f1f5f9;
        --pk-utama-50: #eef2ff;
        --pk-utama-300: #a5b4fc;
        --pk-utama-500: #6366f1;
        --pk-utama-600: #4f46e5;
        --pk-utama-700: #4338ca;
        --pk-utama-900: #312e81;
        --pk-active-bg: var(--pk-utama-50);
        --pk-active-text: var(--pk-utama-700);
        --pk-active-icon: var(--pk-utama-600);
    }
    .dark {
        --pk-bg: #020617;              /* slate-950 */
        --pk-surface: #0f172a;         /* slate-900 */
        --pk-border: #1e293b;          /* slate-800 */
        --pk-line: #1e293b;
        --pk-text: #ffffff;
        --pk-body: #94a3b8;
        --pk-muted: #94a3b8;
        --pk-faint: #64748b;
        --pk-hover: #1e293b;
        --pk-track: #1e293b;
        --pk-active-bg: rgb(99 102 241 / .1);
        --pk-active-text: #a5b4fc;
        --pk-active-icon: #818cf8;
    }

    .fi-body { background: var(--pk-bg) !important; -webkit-font-smoothing: antialiased; }
    [x-cloak] { display: none !important; }

    /* ================= Merek ================= */
    .pk-brand { display: flex; align-items: center; gap: .75rem; min-width: 0; }
    .pk-brand-mark, .pk-auth-mark {
        display: grid; place-items: center; flex: none; font-weight: 700; color: #fff;
        background: linear-gradient(135deg, var(--pk-utama-500), var(--pk-utama-700));
    }
    .pk-brand-mark { width: 2.25rem; height: 2.25rem; border-radius: .75rem; font-size: .875rem; }
    .pk-brand-text { display: flex; flex-direction: column; min-width: 0; }
    .pk-brand-name { font-size: .875rem; font-weight: 700; color: var(--pk-text); line-height: 1.25rem; }
    .pk-brand-sub {
        font-size: .75rem; color: var(--pk-muted); line-height: 1rem;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 12rem;
    }

    /* ================= Tata letak: sidebar penuh + topbar di kanannya ================= */
    .fi-sidebar { background: var(--pk-surface) !important; border-inline-end: 1px solid var(--pk-line); }
    .fi-sidebar-header {
        height: 4rem !important; padding-inline: 1.25rem !important; justify-content: flex-start !important;
        background: transparent !important; box-shadow: none !important; --tw-ring-color: transparent !important;
        border-bottom: 1px solid var(--pk-line);
    }
    .fi-topbar {
        background: color-mix(in srgb, var(--pk-surface) 80%, transparent) !important;
        backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
        box-shadow: none !important; --tw-ring-color: transparent !important;
        border-bottom: 1px solid var(--pk-line);
        gap: .75rem;
    }
    .pk-topbar-context {
        font-size: .875rem; font-weight: 600; color: var(--pk-text);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-inline-start: .25rem;
    }
    @media (min-width: 1024px) {
        /* Sidebar Filament berposisi sticky di bawah topbar; ditarik ke atas
           setinggi topbar supaya tampil penuh seperti SIMPEG. */
        .fi-body-has-topbar .fi-sidebar {
            top: 0 !important; height: 100dvh !important; z-index: 40 !important;
            margin-top: calc(-1 * var(--topbar-height)) !important;
        }
        .fi-body-has-topbar .fi-sidebar-header { display: flex !important; }
        .fi-topbar { padding-inline-start: calc(var(--sidebar-width) + 1.5rem) !important; padding-inline-end: 1.5rem !important; }
        .fi-topbar-start .fi-logo, .fi-topbar-start a:has(.fi-logo) { display: none !important; }
        .pk-topbar-context { margin-inline-start: 0; }
    }
    @media (max-width: 1023px) { .pk-topbar-context { display: none; } }

    .pk-icon-btn {
        display: grid; place-items: center; width: 2.5rem; height: 2.5rem; border-radius: .75rem;
        color: var(--pk-muted); transition: background-color .15s, color .15s;
    }
    .pk-icon-btn:hover { background: var(--pk-hover); color: var(--pk-text); }
    .pk-icon-btn svg { width: 1.25rem; height: 1.25rem; }

    .fi-topbar-end { gap: .5rem !important; }
    .pk-user-identity { display: none; flex-direction: column; line-height: 1.15; margin-inline-start: -.15rem; padding-inline-end: .25rem; }
    @media (min-width: 640px) { .pk-user-identity { display: flex; } }
    .pk-user-name { font-size: .875rem; font-weight: 600; color: var(--pk-text); max-width: 10rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pk-user-role { font-size: .75rem; color: var(--pk-muted); }
    .fi-user-menu-trigger .fi-avatar, .fi-topbar .fi-avatar { border-radius: 9999px; background: var(--pk-utama-600); }

    /* ================= Navigasi ================= */
    .fi-sidebar-nav { padding: 1.25rem .75rem !important; row-gap: 1.5rem !important; }
    .fi-sidebar-nav-groups { margin-inline: 0 !important; row-gap: 1.5rem !important; }
    .fi-sidebar-group-btn { padding: 0 .75rem !important; margin-bottom: .25rem; }
    .fi-sidebar-group-label {
        font-size: 11px !important; font-weight: 700 !important; letter-spacing: .05em;
        text-transform: uppercase; color: var(--pk-faint) !important;
    }
    .fi-sidebar-group-items { row-gap: .25rem !important; }
    .fi-sidebar-item-btn { padding: .625rem .75rem !important; border-radius: .75rem !important; gap: .75rem !important; }
    .fi-sidebar-item-label { font-size: .875rem !important; font-weight: 500 !important; color: var(--pk-body) !important; }
    .fi-sidebar-item-btn > .fi-icon { width: 1.25rem !important; height: 1.25rem !important; color: var(--pk-faint) !important; }
    .fi-sidebar-item.fi-sidebar-item-has-url > .fi-sidebar-item-btn:hover { background: var(--pk-hover) !important; }
    .fi-sidebar-item.fi-sidebar-item-has-url > .fi-sidebar-item-btn:hover > .fi-sidebar-item-label { color: var(--pk-text) !important; }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn { background: var(--pk-active-bg) !important; }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-sidebar-item-label { color: var(--pk-active-text) !important; }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-icon { color: var(--pk-active-icon) !important; }
    /* Penanda antrean: kuning, hanya muncul bila ada yang perlu ditindak. */
    .fi-sidebar-item .fi-badge {
        background: #f59e0b !important; color: #fff !important; --tw-ring-color: transparent !important;
        border-radius: 9999px !important; font-size: 11px !important; font-weight: 700 !important; padding-inline: .4rem !important;
    }

    /* ================= Kepala halaman ================= */
    .fi-main { padding-top: 2rem; }
    .fi-header-heading { font-size: 1.5rem !important; line-height: 2rem !important; font-weight: 700 !important; letter-spacing: -.025em !important; }
    .fi-header-subheading { font-size: .875rem !important; margin-top: .25rem !important; color: var(--pk-muted) !important; }
    .pk-scope-pill {
        display: inline-flex; align-items: center; gap: .375rem; border-radius: 9999px; padding: .25rem .625rem;
        font-size: .75rem; font-weight: 600; color: var(--pk-utama-700); background: var(--pk-utama-50);
        box-shadow: inset 0 0 0 1px rgb(99 102 241 / .2); white-space: nowrap;
    }
    .dark .pk-scope-pill { color: #a5b4fc; background: rgb(99 102 241 / .1); }
    .pk-scope-pill .pk-dot { width: .375rem; height: .375rem; border-radius: 9999px; background: currentColor; }
    .pk-scope-pill svg { width: .875rem; height: .875rem; }

    /* ================= Kartu ================= */
    .fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat {
        border-radius: 1rem !important;
        background: var(--pk-surface) !important;
        box-shadow: 0 1px 2px rgb(0 0 0 / .05) !important;
        --tw-ring-color: var(--pk-border) !important;
    }
    .fi-section-header { padding: 1rem 1.25rem !important; }
    .fi-section-header-heading, .fi-ta-header-heading { font-size: 1rem !important; font-weight: 600 !important; color: var(--pk-text) !important; }
    .fi-section-header-description, .fi-ta-header-description { font-size: .875rem !important; color: var(--pk-muted) !important; }
    .fi-section-content { padding: 1.25rem !important; }

    /* ================= Tabel ================= */
    .fi-ta-header-cell {
        font-size: .75rem !important; font-weight: 600 !important; color: var(--pk-muted) !important;
        text-transform: uppercase; letter-spacing: .04em;
        background: color-mix(in srgb, var(--pk-bg) 85%, var(--pk-surface)) !important;
    }
    .fi-ta-header-cell * { font-size: inherit !important; color: inherit; }
    .fi-ta-row:hover { background: color-mix(in srgb, var(--pk-hover) 60%, transparent); }

    /* ================= Isian & tombol ================= */
    .fi-input-wrp { border-radius: .75rem !important; }
    .fi-btn { border-radius: .75rem !important; font-weight: 600 !important; }
    .fi-btn.fi-color-primary.fi-bg-color-400,
    .fi-btn.fi-color-primary.fi-bg-color-600 { background: var(--pk-utama-600) !important; color: #fff !important; }
    .fi-btn.fi-color-primary.fi-bg-color-400:hover,
    .fi-btn.fi-color-primary.fi-bg-color-600:hover { background: var(--pk-utama-700) !important; }
    .fi-btn.fi-color-primary.fi-bg-color-400 .fi-icon,
    .fi-btn.fi-color-primary.fi-bg-color-600 .fi-icon { color: #fff !important; }
    .fi-dropdown-panel, .fi-modal-window { border-radius: 1rem !important; }

    /* ================= Kartu angka (gaya SIMPEG) ================= */
    .fi-wi-stats-overview .fi-section { background: transparent !important; box-shadow: none !important; --tw-ring-color: transparent !important; }
    .fi-wi-stats-overview .fi-section-content { padding: 0 !important; gap: 1rem !important; }
    .fi-wi-stats-overview-stat { padding: 1.25rem !important; border: 1px solid var(--pk-border); }
    .fi-wi-stats-overview-stat-content {
        display: grid !important; grid-template-columns: 3rem minmax(0, 1fr); column-gap: 1rem; align-items: center;
    }
    .fi-wi-stats-overview-stat-label-ctn { display: contents !important; }
    .fi-wi-stats-overview-stat-label-ctn > .fi-icon {
        grid-column: 1; grid-row: 1 / span 3; box-sizing: content-box;
        width: 1.5rem !important; height: 1.5rem !important; padding: .75rem; border-radius: 1rem; color: #fff !important;
        background: linear-gradient(135deg, var(--pk-from, #6366f1), var(--pk-to, #4338ca));
    }
    .fi-wi-stats-overview-stat-value {
        grid-column: 2; grid-row: 1; margin: 0 !important;
        font-size: 1.5rem !important; line-height: 2rem !important; font-weight: 700 !important;
        letter-spacing: -.025em; font-variant-numeric: tabular-nums; color: var(--pk-text) !important;
    }
    .fi-wi-stats-overview-stat-label {
        grid-column: 2; grid-row: 2; font-size: .875rem !important; font-weight: 400 !important; color: var(--pk-muted) !important;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .fi-wi-stats-overview-stat-description { grid-column: 2; grid-row: 3; font-size: .75rem !important; margin-top: .125rem; color: var(--pk-faint) !important; }
    .fi-wi-stats-overview-stat-description * { color: inherit !important; }
    .pk-stat-primary { --pk-from: #6366f1; --pk-to: #4338ca; }
    .pk-stat-success { --pk-from: #10b981; --pk-to: #047857; }
    .pk-stat-warning { --pk-from: #f59e0b; --pk-to: #ea580c; }
    .pk-stat-info    { --pk-from: #0ea5e9; --pk-to: #0369a1; }
    .pk-stat-danger  { --pk-from: #f43f5e; --pk-to: #be123c; }

    /* ================= Panel daftar & bar progres ================= */
    .pk-list { display: grid; gap: .875rem; }
    .pk-list-row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; font-size: .875rem; margin-bottom: .375rem; }
    .pk-list-label { color: var(--pk-body); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dark .pk-list-label { color: #cbd5e1; }
    .pk-list-value { flex: none; font-weight: 600; color: var(--pk-text); font-variant-numeric: tabular-nums; }
    .pk-list-value small { font-weight: 400; font-size: .75rem; color: var(--pk-muted); margin-inline-start: .5rem; display: inline-block; min-width: 2.5rem; text-align: right; }
    .pk-track { height: .5rem; border-radius: 9999px; overflow: hidden; background: var(--pk-track); }
    .pk-track.pk-thin { height: .375rem; }
    .pk-fill { height: 100%; border-radius: 9999px; transition: width .3s; background: var(--pk-utama-500); }
    .pk-fill.hijau { background: #10b981; } .pk-fill.kuning { background: #f59e0b; } .pk-fill.oranye { background: #fb923c; }
    .pk-fill.biru { background: #0ea5e9; } .pk-fill.merah { background: #f43f5e; } .pk-fill.abu { background: #94a3b8; }
    .pk-divided { display: grid; }
    .pk-divided > li { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding-block: .625rem; border-top: 1px solid var(--pk-hover); font-size: .875rem; }
    .pk-divided > li:first-child { border-top: 0; padding-top: 0; }
    .pk-divided > li:last-child { padding-bottom: 0; }
    .pk-empty { font-size: .875rem; color: var(--pk-muted); text-align: center; padding: 1.5rem 0; }

    /* ================= Login (gaya SIMPEG) ================= */
    .fi-simple-layout:has(.pk-auth-aside) { background: var(--pk-surface); }
    .fi-simple-layout:has(.pk-auth-aside) .fi-simple-main {
        box-shadow: none !important; --tw-ring-color: transparent !important; background: transparent !important;
        max-width: 24rem !important; padding-inline: 1.25rem !important;
    }
    .fi-simple-header { align-items: flex-start !important; text-align: left !important; }
    .fi-simple-header-heading { font-size: 1.5rem !important; font-weight: 700 !important; letter-spacing: -.025em; text-align: left !important; }
    .fi-simple-header-subheading { font-size: .875rem !important; color: var(--pk-muted) !important; text-align: left !important; }
    .pk-auth-aside { display: none; }
    @media (min-width: 1024px) {
        .fi-simple-layout:has(.pk-auth-aside) { display: grid !important; grid-template-columns: 1fr 1fr; align-items: stretch; }
        .fi-simple-layout:has(.pk-auth-aside) .fi-simple-header .fi-logo { display: none !important; }
        .pk-auth-aside {
            position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
            padding: 3rem; color: #fff;
            background: linear-gradient(to bottom right, var(--pk-utama-700), var(--pk-utama-600), var(--pk-utama-900));
        }
        .pk-auth-glow { position: absolute; width: 24rem; height: 24rem; border-radius: 9999px; filter: blur(64px); pointer-events: none; }
        .pk-auth-glow-a { top: -6rem; right: -6rem; background: rgb(255 255 255 / .1); }
        .pk-auth-glow-b { bottom: -8rem; left: -4rem; background: rgb(165 180 252 / .2); }
        .pk-auth-brand, .pk-auth-copy, .pk-auth-foot { position: relative; }
        .pk-auth-brand { display: flex; align-items: center; gap: .75rem; }
        .pk-auth-mark { width: 2.75rem; height: 2.75rem; border-radius: 1rem; font-size: 1rem; background: rgb(255 255 255 / .15); backdrop-filter: blur(8px); }
        .pk-auth-brand-name { font-size: 1rem; font-weight: 700; }
        .pk-auth-brand-sub { font-size: .875rem; color: #e0e7ff; }
        .pk-auth-copy { max-width: 28rem; }
        .pk-auth-copy h2 { font-size: 1.875rem; line-height: 1.25; font-weight: 700; }
        .pk-auth-copy p { margin-top: 1rem; color: #e0e7ff; line-height: 1.6; }
        .pk-auth-foot { font-size: .75rem; color: #c7d2fe; }
    }
</style>
