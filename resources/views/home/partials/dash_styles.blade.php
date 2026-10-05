{{-- Dashboard "dash-v2" look (same design as the babekhyber dashboard). Scoped to .dash-v2 so nothing leaks into the
     rest of the app. Every class/id that public/js/home.js fills (.total_sell, .net, #dashboard_date_filter, ...) is kept. --}}
<style>
    .dash-v2 {
        --dv-ink: #1f2d27;
        --dv-muted: #6b7a73;
        --dv-line: #e3ece7;
        --dv-card: #ffffff;
        --dv-green: #2f9e6e;   --dv-green-soft: #e8f6ef;
        --dv-blue: #3b82c4;    --dv-blue-soft: #e9f2fb;
        --dv-orange: #e58a2b;  --dv-orange-soft: #fdf1e3;
        --dv-red: #d9534f;     --dv-red-soft: #fcecec;
        --dv-violet: #8a63c9;  --dv-violet-soft: #f2ecfb;
        --dv-teal: #1f9fa8;    --dv-teal-soft: #e5f6f7;
        color: var(--dv-ink);
        padding: 14px 20px 4px;
    }
    .dash-v2 a { color: inherit; text-decoration: none; }

    /* header: avatar, greeting, controls */
    .dash-v2 .dv-head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 14px; }
    .dash-v2 .dv-avatar { width: 52px; height: 52px; border-radius: 12px; padding: 2px; flex: none;
        background: linear-gradient(135deg, #2f9e6e, #1f9fa8); box-shadow: 0 4px 12px rgba(47, 158, 110, .25);
        display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 18px; letter-spacing: .02em; }
    .dash-v2 .dv-title h2 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.01em; color: var(--dv-ink); }
    .dash-v2 .dv-title .hello { font-size: 14px; font-weight: 600; color: var(--dv-green); }
    .dash-v2 .dv-title .sub { font-size: 13px; color: var(--dv-muted); }
    .dash-v2 .dv-tools { margin-left: auto; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .dash-v2 .dv-pill { background: var(--dv-card); border: 1px solid var(--dv-line); border-radius: 12px; padding: 8px 14px;
        font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
    .dash-v2 #dashboard_date_filter { border-radius: 12px; background: var(--dv-green); border: 1px solid var(--dv-green); color: #fff;
        font-size: 13px; font-weight: 600; padding: 8px 14px; display: inline-flex; align-items: center; gap: 8px; }
    .dash-v2 #dashboard_date_filter:hover { background: #23845a; }
    .dash-v2 .dv-location { min-width: 220px; }

    /* stat cards */
    .dash-v2 .dv-grid { display: grid; gap: 10px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .dash-v2 .dv-stat { border-radius: 12px; padding: 12px 14px; display: flex; align-items: center; gap: 12px;
        border: 1px solid transparent; transition: transform .15s ease, box-shadow .15s ease; min-width: 0; }
    .dash-v2 a.dv-stat:hover, .dash-v2 .dv-stat:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(31, 45, 39, .08); }
    .dash-v2 .dv-stat .ic { width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; font-size: 17px;
        color: #fff; flex: none; }
    .dash-v2 .dv-stat .txt { min-width: 0; }
    .dash-v2 .dv-stat .lbl { font-size: 12px; font-weight: 600; line-height: 1.25; }
    .dash-v2 .dv-stat .num { font-size: 19px; font-weight: 700; margin: 0; line-height: 1.25; font-variant-numeric: tabular-nums;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--dv-ink); }
    .dash-v2 .dv-stat .sub { font-size: 11px; color: var(--dv-muted); margin: 0; line-height: 1.2; }
    .dash-v2 .dv-stat.big .ic { width: 46px; height: 46px; font-size: 20px; }
    .dash-v2 .dv-stat.big .num { font-size: 22px; font-weight: 800; }
    .dash-v2 .t-blue   { background: var(--dv-blue-soft);   border-color: #d5e6f6; } .dash-v2 .t-blue .ic { background: var(--dv-blue); }     .dash-v2 .t-blue .lbl { color: var(--dv-blue); }
    .dash-v2 .t-green  { background: var(--dv-green-soft);  border-color: #cfe9dc; } .dash-v2 .t-green .ic { background: var(--dv-green); }   .dash-v2 .t-green .lbl { color: #1d7a52; }
    .dash-v2 .t-orange { background: var(--dv-orange-soft); border-color: #f7dfc2; } .dash-v2 .t-orange .ic { background: var(--dv-orange); } .dash-v2 .t-orange .lbl { color: #b2641a; }
    .dash-v2 .t-red    { background: var(--dv-red-soft);    border-color: #f5d3d2; } .dash-v2 .t-red .ic { background: var(--dv-red); }       .dash-v2 .t-red .lbl { color: #b53e3a; }
    .dash-v2 .t-violet { background: var(--dv-violet-soft); border-color: #e2d6f4; } .dash-v2 .t-violet .ic { background: var(--dv-violet); } .dash-v2 .t-violet .lbl { color: #6a47a8; }
    .dash-v2 .t-teal   { background: var(--dv-teal-soft);   border-color: #cdebed; } .dash-v2 .t-teal .ic { background: var(--dv-teal); }     .dash-v2 .t-teal .lbl { color: #157a82; }

    /* panels */
    .dash-v2 .dv-row { display: grid; gap: 12px; margin-top: 12px; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); }
    .dash-v2 .dv-panel { background: var(--dv-card); border: 1px solid var(--dv-line); border-radius: 14px; padding: 12px 14px;
        box-shadow: 0 2px 10px rgba(31, 45, 39, .04); }
    .dash-v2 .dv-panel-h { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
    .dash-v2 .dv-panel-h .ph-ic { width: 30px; height: 30px; border-radius: 9px; background: var(--dv-green-soft); color: var(--dv-green);
        display: grid; place-items: center; font-size: 15px; }
    .dash-v2 .dv-panel-h h5 { margin: 0; font-size: 14px; font-weight: 700; color: var(--dv-ink); }
    .dash-v2 .dv-sub-h { margin: 12px 0 6px; font-size: 12.5px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .dash-v2 .dv-sub-h i { color: var(--dv-green); }
    .dash-v2 .dv-sub-h.is-red i { color: var(--dv-red); }
    .dash-v2 .dv-acc { display: grid; gap: 8px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .dash-v2 .dv-acc .dv-stat { padding: 9px 10px; gap: 10px; }
    .dash-v2 .dv-acc .dv-stat .ic { width: 32px; height: 32px; font-size: 14px; border-radius: 9px; }
    .dash-v2 .dv-acc .dv-stat .num { font-size: 15.5px; }

    /* financial overview donut */
    .dash-v2 .dv-donut-wrap { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; }
    .dash-v2 #dv_fin_donut { width: 190px; height: 190px; flex: none; }
    .dash-v2 .dv-legend { flex: 1; min-width: 200px; list-style: none; margin: 0; padding: 0; }
    .dash-v2 .dv-legend li { display: flex; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px dashed var(--dv-line); font-size: 13px; }
    .dash-v2 .dv-legend li:last-child { border-bottom: 0; }
    .dash-v2 .dv-legend .dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
    .dash-v2 .dv-legend .v { margin-left: auto; font-weight: 700; font-variant-numeric: tabular-nums; }

    @media (max-width: 1199.98px) {
        .dash-v2 .dv-grid, .dash-v2 .dv-acc { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .dash-v2 .dv-row { grid-template-columns: minmax(0, 1fr); }
    }
    @media (max-width: 575.98px) {
        .dash-v2 { padding: 10px 12px 4px; }
        .dash-v2 .dv-grid, .dash-v2 .dv-acc { grid-template-columns: minmax(0, 1fr); }
        .dash-v2 .dv-tools { margin-left: 0; width: 100%; }
        .dash-v2 .dv-title h2 { font-size: 19px; }
    }
</style>
