import { Controller } from "@hotwired/stimulus";

/**
 * WorkflowVisualizer Stimulus Controller
 *
 * Renders an interactive SVG state-machine diagram from workflow states and
 * transitions. States are grouped into swim-lane columns by status_category
 * and colored accordingly. Hover on a node to highlight its connections.
 *
 * Values:
 *   states      - Array of {id, name, slug, label, state_type, status_category}
 *   transitions - Array of {id, name, label, from_state_id, to_state_id, is_automatic}
 *
 * Targets:
 *   canvas - Container element where the SVG is rendered
 */
class WorkflowVisualizerController extends Controller {
    static values = {
        states: Array,
        transitions: Array,
    };

    static targets = ["canvas"];

    // Layout constants
    NODE_W = 156;
    NODE_H = 48;
    NODE_R = 10;
    COL_GAP = 110;
    ROW_GAP = 64;
    PAD_X = 40;
    PAD_TOP = 52;
    PAD_BOT = 32;

    // Category display order (left → right)
    CAT_ORDER = [
        "In Progress", "Pending", "Draft",
        "Review", "In Review", "Approval",
        "Scheduling", "To Give",
        "Active",
        "Completed", "Closed",
        "Rejected", "Cancelled",
    ];

    // Category → color scheme (Bootstrap-inspired)
    CAT_STYLE = {
        "In Progress": { bg: "#fff3cd", bd: "#ffc107", tx: "#664d03", lane: "rgba(255,193,7,.07)" },
        "Pending":     { bg: "#fff3cd", bd: "#ffc107", tx: "#664d03", lane: "rgba(255,193,7,.07)" },
        "Draft":       { bg: "#fff3cd", bd: "#e5a100", tx: "#664d03", lane: "rgba(229,161,0,.07)" },
        "Review":      { bg: "#cfe2ff", bd: "#0d6efd", tx: "#052c65", lane: "rgba(13,110,253,.05)" },
        "In Review":   { bg: "#cfe2ff", bd: "#0d6efd", tx: "#052c65", lane: "rgba(13,110,253,.05)" },
        "Approval":    { bg: "#d0e4ff", bd: "#3b82f6", tx: "#1e3a5f", lane: "rgba(59,130,246,.05)" },
        "Scheduling":  { bg: "#e0cffc", bd: "#6f42c1", tx: "#3d1f7c", lane: "rgba(111,66,193,.05)" },
        "To Give":     { bg: "#cff4fc", bd: "#0dcaf0", tx: "#055160", lane: "rgba(13,202,240,.05)" },
        "Active":      { bg: "#d1e7dd", bd: "#198754", tx: "#0a3622", lane: "rgba(25,135,84,.05)" },
        "Completed":   { bg: "#d1e7dd", bd: "#20c997", tx: "#0a3622", lane: "rgba(32,201,151,.05)" },
        "Closed":      { bg: "#e2e3e5", bd: "#6c757d", tx: "#41464b", lane: "rgba(108,117,125,.06)" },
        "Rejected":    { bg: "#f8d7da", bd: "#dc3545", tx: "#58151c", lane: "rgba(220,53,69,.05)" },
        "Cancelled":   { bg: "#e2e3e5", bd: "#adb5bd", tx: "#495057", lane: "rgba(173,181,189,.06)" },
    };
    DEFAULT_STYLE = { bg: "#f8f9fa", bd: "#adb5bd", tx: "#495057", lane: "rgba(173,181,189,.06)" };

    /* ── lifecycle ─────────────────────────────────────── */

    connect() { this.render(); }
    statesValueChanged() { this.render(); }
    transitionsValueChanged() { this.render(); }

    /* ── public render ─────────────────────────────────── */

    render() {
        const states = this.statesValue || [];
        const trans = this.transitionsValue || [];

        if (!states.length) {
            this.canvasTarget.innerHTML =
                '<div class="text-center text-muted py-5">' +
                '<i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>' +
                'No states defined yet. Add states to visualize the workflow.</div>';
            return;
        }

        const layout = this._layout(states);
        this.canvasTarget.innerHTML = this._svg(layout, states, trans);
        this._interactions();
    }

    /* ── layout ────────────────────────────────────────── */

    _style(cat) { return this.CAT_STYLE[cat] || this.DEFAULT_STYLE; }

    _layout(states) {
        // Group by status_category
        const groups = new Map();
        states.forEach(s => {
            const cat = s.status_category || "Other";
            if (!groups.has(cat)) groups.set(cat, []);
            groups.get(cat).push(s);
        });

        // Order categories
        const cats = [];
        this.CAT_ORDER.forEach(c => { if (groups.has(c)) cats.push(c); });
        groups.forEach((_, c) => { if (!cats.includes(c)) cats.push(c); });

        // Sort within each group: initial → alphabetical → final
        const typeRank = { initial: 0, intermediate: 1, final: 2 };
        groups.forEach(list => list.sort((a, b) => {
            const d = (typeRank[a.state_type] ?? 1) - (typeRank[b.state_type] ?? 1);
            return d !== 0 ? d : (a.label || "").localeCompare(b.label || "");
        }));

        // Position nodes
        const pos = new Map();
        const cols = [];
        let cx = this.PAD_X;

        cats.forEach((cat, ci) => {
            const list = groups.get(cat);
            cols.push({ cat, x: cx, n: list.length, s: this._style(cat) });
            list.forEach((st, ri) => {
                pos.set(st.id, {
                    x: cx,
                    y: this.PAD_TOP + ri * (this.NODE_H + this.ROW_GAP),
                    col: ci, row: ri,
                });
            });
            cx += this.NODE_W + this.COL_GAP;
        });

        const maxR = Math.max(...[...groups.values()].map(g => g.length), 1);
        const W = cx - this.COL_GAP + this.PAD_X;
        const H = this.PAD_TOP + maxR * (this.NODE_H + this.ROW_GAP) - this.ROW_GAP + this.PAD_BOT;

        return { pos, cols, W, H };
    }

    /* ── SVG construction ──────────────────────────────── */

    _svg(layout, states, trans) {
        const { pos, cols, W, H } = layout;
        const parts = [];

        parts.push(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" class="wf-svg" preserveAspectRatio="xMidYMid meet">`);

        // Defs
        parts.push(`<defs>
            <marker id="wf-arrow" viewBox="0 0 10 7" refX="9" refY="3.5"
                    markerWidth="9" markerHeight="7" orient="auto-start-reverse">
                <path d="M0,0.5 L9,3.5 L0,6.5z" fill="#9ca3af"/>
            </marker>
            <marker id="wf-arrow-hi" viewBox="0 0 10 7" refX="9" refY="3.5"
                    markerWidth="9" markerHeight="7" orient="auto-start-reverse">
                <path d="M0,0.5 L9,3.5 L0,6.5z" fill="#0d6efd"/>
            </marker>
            <filter id="wf-sh" x="-8%" y="-8%" width="116%" height="124%">
                <feDropShadow dx="0" dy="1.5" stdDeviation="2.5" flood-opacity="0.08"/>
            </filter>
        </defs>`);

        // Swim-lane backgrounds + headers
        cols.forEach(c => {
            const lx = c.x - 14, lw = this.NODE_W + 28;
            parts.push(`<rect x="${lx}" y="8" width="${lw}" height="${H - 16}" rx="10" fill="${c.s.lane}"/>`);
            parts.push(`<text x="${c.x + this.NODE_W / 2}" y="30" text-anchor="middle" font-size="10.5" font-weight="600" fill="${c.s.tx}" opacity=".55" letter-spacing=".3">${this._esc(c.cat.toUpperCase())}</text>`);
        });

        // Edges (drawn first, behind nodes)
        parts.push('<g class="wf-edges">');
        trans.forEach((t, i) => parts.push(this._edge(t, pos, i)));
        parts.push('</g>');

        // Nodes (drawn on top)
        parts.push('<g class="wf-nodes">');
        states.forEach(s => parts.push(this._node(s, pos)));
        parts.push('</g>');

        parts.push('</svg>');
        return parts.join('\n');
    }

    /* ── Node rendering ────────────────────────────────── */

    _node(st, pos) {
        const p = pos.get(st.id);
        if (!p) return '';
        const { x, y } = p;
        const s = this._style(st.status_category);
        const init = st.state_type === 'initial';
        const fin = st.state_type === 'final';
        const sw = init || fin ? 2.5 : 1.5;
        const parts = [];

        parts.push(`<g class="wf-node" data-id="${st.id}">`);

        // Shadow + background
        parts.push(`<rect x="${x}" y="${y}" width="${this.NODE_W}" height="${this.NODE_H}" rx="${this.NODE_R}" fill="white" filter="url(#wf-sh)"/>`);
        parts.push(`<rect class="wf-node-bg" x="${x}" y="${y}" width="${this.NODE_W}" height="${this.NODE_H}" rx="${this.NODE_R}" fill="${s.bg}" stroke="${s.bd}" stroke-width="${sw}"/>`);

        // State-type icon
        const iy = y + this.NODE_H / 2;
        if (init) {
            // Play icon
            parts.push(`<circle cx="${x + 15}" cy="${iy}" r="7" fill="${s.bd}" opacity=".85"/>`);
            parts.push(`<polygon points="${x + 13},${iy - 4} ${x + 19},${iy} ${x + 13},${iy + 4}" fill="#fff"/>`);
        } else if (fin) {
            // Stop icon (double circle)
            parts.push(`<circle cx="${x + 15}" cy="${iy}" r="7" fill="none" stroke="${s.bd}" stroke-width="1.5"/>`);
            parts.push(`<circle cx="${x + 15}" cy="${iy}" r="3.5" fill="${s.bd}"/>`);
        }

        // Label
        const label = st.label || st.name;
        const hasIcon = init || fin;
        const tx = hasIcon ? x + 28 : x + this.NODE_W / 2;
        const anchor = hasIcon ? 'start' : 'middle';
        const maxCh = hasIcon ? 14 : 17;
        const disp = label.length > maxCh ? label.substring(0, maxCh - 1) + '…' : label;

        parts.push(`<text x="${tx}" y="${iy + 4.5}" text-anchor="${anchor}" font-size="12.5" font-weight="500" fill="${s.tx}">${this._esc(disp)}</text>`);

        // Accessible tooltip
        parts.push(`<title>${this._esc(label)} [${st.state_type}]${st.status_category ? ' — ' + st.status_category : ''}</title>`);

        parts.push('</g>');
        return parts.join('');
    }

    /* ── Edge rendering ────────────────────────────────── */

    _edge(t, pos, idx) {
        const from = pos.get(t.from_state_id);
        const to = pos.get(t.to_state_id);
        if (!from || !to) return '';

        if (t.from_state_id === t.to_state_id) return this._selfLoop(from, t);

        const d = this._path(from, to, idx);
        const dash = t.is_automatic ? ' stroke-dasharray="5,3"' : '';

        return `<g class="wf-edge" data-from="${t.from_state_id}" data-to="${t.to_state_id}">` +
            `<path d="${d}" fill="none" stroke="#9ca3af" stroke-width="1.5" marker-end="url(#wf-arrow)"${dash}/>` +
            `<title>${this._esc(t.label || t.name)}</title></g>`;
    }

    _path(from, to, idx) {
        const W = this.NODE_W, H = this.NODE_H;
        const jit = ((idx % 7) - 3) * 3.5; // jitter to spread overlapping edges

        if (from.col < to.col) {
            // Forward: right-center → left-center
            const sx = from.x + W, sy = from.y + H / 2 + jit;
            const ex = to.x,       ey = to.y + H / 2 + jit;
            const dx = ex - sx;
            return `M${sx},${sy} C${sx + dx * .38},${sy} ${ex - dx * .38},${ey} ${ex},${ey}`;
        }

        if (from.col === to.col) {
            // Same column: arc via the right side
            const down = from.row < to.row;
            const sy = from.y + (down ? H - 4 : 4);
            const ey = to.y + (down ? 4 : H - 4);
            const sx = from.x + W, ex = to.x + W;
            const dist = Math.abs(from.row - to.row);
            const bulge = 32 + dist * 18 + (idx % 4) * 7;
            return `M${sx},${sy} C${sx + bulge},${sy} ${ex + bulge},${ey} ${ex},${ey}`;
        }

        // Backward: arc below diagram
        const sy = from.y + H / 2 + jit;
        const ey = to.y + H / 2 + jit;
        const sx = from.x;
        const ex = to.x + W;
        const below = Math.max(from.y, to.y) + H + 45 + (idx % 4) * 12;
        return `M${sx},${sy} C${sx - 30},${below} ${ex + 30},${below} ${ex},${ey}`;
    }

    _selfLoop(p, t) {
        const cx = p.x + this.NODE_W / 2;
        const top = p.y - 8;
        return `<g class="wf-edge" data-from="${t.from_state_id}" data-to="${t.to_state_id}">` +
            `<path d="M${cx - 14},${top} C${cx - 14},${top - 22} ${cx + 14},${top - 22} ${cx + 14},${top}" ` +
            `fill="none" stroke="#9ca3af" stroke-width="1.5" marker-end="url(#wf-arrow)"/>` +
            `<title>${this._esc(t.label || t.name)} (loop)</title></g>`;
    }

    /* ── Interactions ──────────────────────────────────── */

    _interactions() {
        const svg = this.canvasTarget.querySelector('svg');
        if (!svg) return;

        svg.querySelectorAll('.wf-node').forEach(node => {
            node.style.cursor = 'pointer';

            node.addEventListener('mouseenter', () => {
                const id = node.dataset.id;
                svg.classList.add('wf-focus');
                node.classList.add('wf-hi');

                const linked = new Set([id]);
                svg.querySelectorAll('.wf-edge').forEach(e => {
                    if (e.dataset.from === id || e.dataset.to === id) {
                        e.classList.add('wf-hi');
                        linked.add(e.dataset.from);
                        linked.add(e.dataset.to);
                    }
                });
                svg.querySelectorAll('.wf-node').forEach(n => {
                    if (!linked.has(n.dataset.id)) n.classList.add('wf-lo');
                });
            });

            node.addEventListener('mouseleave', () => {
                svg.classList.remove('wf-focus');
                svg.querySelectorAll('.wf-hi,.wf-lo').forEach(el =>
                    el.classList.remove('wf-hi', 'wf-lo'));
            });
        });
    }

    /* ── Utilities ─────────────────────────────────────── */

    _esc(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
}

if (!window.Controllers) { window.Controllers = {}; }
window.Controllers["workflow-visualizer"] = WorkflowVisualizerController;
