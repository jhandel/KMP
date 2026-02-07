import { Controller } from "@hotwired/stimulus";

/**
 * WorkflowVisualizer Stimulus Controller
 *
 * Renders workflow definitions in two modes:
 *   - "flow" (default): Power Automate / Azure Logic Apps–style vertical card flow
 *   - "diagram": Horizontal SVG swim-lane state-machine diagram
 *
 * Values:  states, transitions, mode ("flow"|"diagram")
 * Targets: canvas, modeBtn
 */
class WorkflowVisualizerController extends Controller {
    static values = {
        states: Array,
        transitions: Array,
        mode: { type: String, default: "flow" },
    };
    static targets = ["canvas", "modeBtn"];

    /* ── category ordering & color palette ──────────────── */

    CAT_ORDER = [
        "In Progress", "Pending", "Draft",
        "Review", "In Review", "Approval",
        "Scheduling", "To Give",
        "Active",
        "Completed", "Closed",
        "Rejected", "Cancelled",
    ];
    STYLES = {
        "In Progress": { accent: "#f59e0b", bg: "#fffbeb", tx: "#92400e", icon: "bi-hourglass-split" },
        "Pending":     { accent: "#f59e0b", bg: "#fffbeb", tx: "#92400e", icon: "bi-hourglass" },
        "Draft":       { accent: "#d97706", bg: "#fffbeb", tx: "#92400e", icon: "bi-pencil-square" },
        "Review":      { accent: "#3b82f6", bg: "#eff6ff", tx: "#1e3a8a", icon: "bi-search" },
        "In Review":   { accent: "#3b82f6", bg: "#eff6ff", tx: "#1e3a8a", icon: "bi-search" },
        "Approval":    { accent: "#6366f1", bg: "#eef2ff", tx: "#3730a3", icon: "bi-shield-check" },
        "Scheduling":  { accent: "#8b5cf6", bg: "#f5f3ff", tx: "#5b21b6", icon: "bi-calendar-event" },
        "To Give":     { accent: "#06b6d4", bg: "#ecfeff", tx: "#155e75", icon: "bi-gift" },
        "Active":      { accent: "#10b981", bg: "#ecfdf5", tx: "#065f46", icon: "bi-lightning-charge" },
        "Completed":   { accent: "#14b8a6", bg: "#f0fdfa", tx: "#115e59", icon: "bi-check-circle" },
        "Closed":      { accent: "#6b7280", bg: "#f9fafb", tx: "#374151", icon: "bi-lock" },
        "Rejected":    { accent: "#ef4444", bg: "#fef2f2", tx: "#991b1b", icon: "bi-x-circle" },
        "Cancelled":   { accent: "#9ca3af", bg: "#f9fafb", tx: "#4b5563", icon: "bi-slash-circle" },
    };
    DEF = { accent: "#9ca3af", bg: "#f9fafb", tx: "#4b5563", icon: "bi-circle" };

    /* ── lifecycle ──────────────────────────────────────── */

    connect() { this.render(); }
    statesValueChanged() { this.render(); }
    transitionsValueChanged() { this.render(); }
    modeValueChanged() {
        this.render();
        this._syncModeBtns();
    }

    /* ── public actions (mode toggle) ──────────────────── */

    setFlowMode() { this.modeValue = "flow"; }
    setDiagramMode() { this.modeValue = "diagram"; }

    /* ── main render dispatcher ─────────────────────────── */

    render() {
        const S = this.statesValue || [];
        const T = this.transitionsValue || [];
        if (!S.length) {
            this.canvasTarget.innerHTML =
                '<div class="text-center text-muted py-5">' +
                '<i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>' +
                'No states defined yet.</div>';
            return;
        }
        this._prep(S, T);
        this.modeValue === "diagram" ? this._svgRender(S, T) : this._flowRender(S, T);
    }

    /* ── shared data prep ──────────────────────────────── */

    _s(cat) { return this.STYLES[cat] || this.DEF; }

    _prep(S, T) {
        this._sm = new Map(S.map(s => [s.id, s]));
        this._out = new Map(); this._in = new Map();
        S.forEach(s => { this._out.set(s.id, []); this._in.set(s.id, []); });
        T.forEach(t => {
            this._out.get(t.from_state_id)?.push(t);
            this._in.get(t.to_state_id)?.push(t);
        });
    }

    _phases(S) {
        const g = new Map();
        S.forEach(s => {
            const c = s.status_category || "Other";
            if (!g.has(c)) g.set(c, []);
            g.get(c).push(s);
        });
        const order = [];
        this.CAT_ORDER.forEach(c => { if (g.has(c)) order.push(c); });
        g.forEach((_, c) => { if (!order.includes(c)) order.push(c); });
        const rank = { initial: 0, intermediate: 1, final: 2 };
        return order.map(c => ({
            cat: c, style: this._s(c),
            states: g.get(c).sort((a, b) => {
                const d = (rank[a.state_type] ?? 1) - (rank[b.state_type] ?? 1);
                return d || (a.label || "").localeCompare(b.label || "");
            }),
        }));
    }

    _syncModeBtns() {
        if (!this.hasModeBtnTarget) return;
        this.modeBtnTargets.forEach(b => {
            const m = b.dataset.mode;
            b.classList.toggle("active", m === this.modeValue);
        });
    }

    /* ════════════════════════════════════════════════════
       FLOW MODE  (Power Automate / Logic Apps style)
       ════════════════════════════════════════════════════ */

    _flowRender(S, T) {
        const phases = this._phases(S);
        const p = [];

        p.push('<div class="wf-flow">');
        p.push(this._pill("start"));
        p.push(this._conn());

        phases.forEach((ph, i) => {
            p.push(this._scope(ph));
            if (i < phases.length - 1) {
                const cross = this._crossTrans(ph, phases[i + 1]);
                p.push(this._conn(cross));
            }
        });

        p.push(this._conn());
        p.push(this._pill("end"));
        p.push('</div>');

        this.canvasTarget.innerHTML = p.join("");
        this._flowHandlers();
    }

    /* Start/End pill */
    _pill(type) {
        const isStart = type === "start";
        const cls = isStart ? "wf-pill-start" : "wf-pill-end";
        const icon = isStart ? "bi-play-fill" : "bi-stop-fill";
        const label = isStart ? "Start" : "End";
        return `<div class="wf-pill ${cls}"><i class="bi ${icon}"></i> ${label}</div>`;
    }

    /* Connector line between scopes */
    _conn(crossTrans) {
        let label = "";
        if (crossTrans && crossTrans.length) {
            const names = [...new Set(crossTrans.map(t => t.label || t.name))];
            if (names.length <= 2) {
                label = names.map(n => `<span class="wf-conn-label">${this._esc(n)}</span>`).join("");
            } else {
                label = `<span class="wf-conn-label">${this._esc(names[0])}</span>` +
                    `<span class="wf-conn-label wf-conn-more">+${names.length - 1} more</span>`;
            }
        }
        return `<div class="wf-conn">${label}<div class="wf-conn-line"></div><div class="wf-conn-arrow"></div></div>`;
    }

    /* Phase scope card */
    _scope(phase) {
        const s = phase.style;
        const p = [];
        p.push(`<div class="wf-scope" style="--wf-a:${s.accent}; --wf-bg:${s.bg}; --wf-tx:${s.tx};">`);

        // Header
        p.push(`<div class="wf-scope-hd">`);
        p.push(`<div class="wf-scope-icon"><i class="bi ${s.icon}"></i></div>`);
        p.push(`<div class="wf-scope-title">${this._esc(phase.cat)}</div>`);
        p.push(`<span class="wf-scope-badge">${phase.states.length}</span>`);
        p.push(`</div>`);

        // Step cards
        p.push(`<div class="wf-scope-body">`);
        phase.states.forEach((st, i) => {
            p.push(this._step(st));
            if (i < phase.states.length - 1) p.push('<div class="wf-step-conn"></div>');
        });
        p.push(`</div></div>`);
        return p.join("");
    }

    /* Individual step card */
    _step(st) {
        const out = this._out.get(st.id) || [];
        const inc = this._in.get(st.id) || [];
        const isInit = st.state_type === "initial";
        const isFin = st.state_type === "final";
        const p = [];

        p.push(`<div class="wf-step" data-state-id="${st.id}">`);
        p.push(`<div class="wf-step-card">`);

        // Icon
        if (isInit) {
            p.push(`<div class="wf-step-dot wf-dot-start"><i class="bi bi-play-fill"></i></div>`);
        } else if (isFin) {
            p.push(`<div class="wf-step-dot wf-dot-end"><i class="bi bi-stop-fill"></i></div>`);
        } else {
            p.push(`<div class="wf-step-dot"><i class="bi bi-circle-fill"></i></div>`);
        }

        // Info
        p.push(`<div class="wf-step-info">`);
        p.push(`<div class="wf-step-name">${this._esc(st.label || st.name)}</div>`);
        const meta = [];
        if (inc.length) meta.push(`← ${inc.length} in`);
        if (out.length) meta.push(`→ ${out.length} out`);
        if (isInit) meta.push("initial");
        if (isFin) meta.push("final");
        p.push(`<div class="wf-step-meta">${meta.join(" · ")}</div>`);
        p.push(`</div>`);

        // Chevron
        p.push(`<i class="bi bi-chevron-down wf-step-chev"></i>`);
        p.push(`</div>`); // .wf-step-card

        // Expandable detail
        p.push(`<div class="wf-step-detail">`);
        if (out.length) {
            p.push(`<div class="wf-detail-section"><div class="wf-detail-label">Outgoing transitions</div><div class="wf-detail-badges">`);
            out.forEach(t => {
                const target = this._sm.get(t.to_state_id);
                const tCat = target?.status_category || "Other";
                const ts = this._s(tCat);
                const auto = t.is_automatic ? ' <i class="bi bi-lightning-charge-fill" title="Automatic"></i>' : "";
                p.push(`<span class="wf-badge" style="--wf-ba:${ts.accent};" title="${this._esc(t.label || t.name)}">→ ${this._esc(target?.label || "?")}${auto}</span>`);
            });
            p.push(`</div></div>`);
        }
        if (inc.length) {
            p.push(`<div class="wf-detail-section"><div class="wf-detail-label">Incoming transitions</div><div class="wf-detail-badges">`);
            inc.forEach(t => {
                const source = this._sm.get(t.from_state_id);
                const sCat = source?.status_category || "Other";
                const ss = this._s(sCat);
                p.push(`<span class="wf-badge" style="--wf-ba:${ss.accent};">← ${this._esc(source?.label || "?")}</span>`);
            });
            p.push(`</div></div>`);
        }
        if (!out.length && !inc.length) {
            p.push('<div class="text-muted small">No transitions connected.</div>');
        }
        p.push(`</div>`); // .wf-step-detail

        p.push(`</div>`); // .wf-step
        return p.join("");
    }

    /* Cross-phase transitions for connector labels */
    _crossTrans(fromPhase, toPhase) {
        const toIds = new Set(toPhase.states.map(s => s.id));
        const result = [];
        fromPhase.states.forEach(s => {
            (this._out.get(s.id) || []).forEach(t => {
                if (toIds.has(t.to_state_id)) result.push(t);
            });
        });
        return result;
    }

    /* Click-to-expand handlers */
    _flowHandlers() {
        this.canvasTarget.querySelectorAll(".wf-step-card").forEach(card => {
            card.addEventListener("click", () => {
                card.closest(".wf-step").classList.toggle("wf-expanded");
            });
        });
    }

    /* ════════════════════════════════════════════════════
       DIAGRAM MODE  (SVG swim-lane state-machine)
       ════════════════════════════════════════════════════ */

    NW = 156; NH = 48; NR = 10;
    CG = 110; RG = 64;
    PX = 40; PT = 52; PB = 32;

    _svgRender(S, T) {
        const L = this._lay(S);
        const p = [];
        p.push(`<div class="wf-diagram-wrap">`);
        p.push(this._svgBuild(L, S, T));
        p.push(`</div>`);
        this.canvasTarget.innerHTML = p.join("");
        this._svgInteract();
    }

    _lay(S) {
        const phases = this._phases(S);
        const pos = new Map();
        const cols = [];
        let cx = this.PX;
        phases.forEach((ph, ci) => {
            cols.push({ cat: ph.cat, x: cx, n: ph.states.length, s: ph.style });
            ph.states.forEach((st, ri) => {
                pos.set(st.id, { x: cx, y: this.PT + ri * (this.NH + this.RG), col: ci, row: ri });
            });
            cx += this.NW + this.CG;
        });
        const mr = Math.max(...phases.map(p => p.states.length), 1);
        return { pos, cols, W: cx - this.CG + this.PX, H: this.PT + mr * (this.NH + this.RG) - this.RG + this.PB };
    }

    _svgBuild(L, S, T) {
        const { pos, cols, W, H } = L;
        const p = [];
        p.push(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" class="wf-svg" preserveAspectRatio="xMidYMid meet">`);
        p.push(`<defs>
            <marker id="wf-arr" viewBox="0 0 10 7" refX="9" refY="3.5" markerWidth="9" markerHeight="7" orient="auto-start-reverse"><path d="M0,.5 L9,3.5 L0,6.5z" fill="#9ca3af"/></marker>
            <marker id="wf-arr-h" viewBox="0 0 10 7" refX="9" refY="3.5" markerWidth="9" markerHeight="7" orient="auto-start-reverse"><path d="M0,.5 L9,3.5 L0,6.5z" fill="#3b82f6"/></marker>
            <filter id="wf-sh" x="-8%" y="-8%" width="116%" height="124%"><feDropShadow dx="0" dy="1.5" stdDeviation="2.5" flood-opacity=".08"/></filter>
        </defs>`);

        cols.forEach(c => {
            const lx = c.x - 14, lw = this.NW + 28;
            p.push(`<rect x="${lx}" y="8" width="${lw}" height="${H - 16}" rx="10" fill="${c.s.bg}" opacity=".45"/>`);
            p.push(`<text x="${c.x + this.NW / 2}" y="30" text-anchor="middle" font-size="10" font-weight="600" fill="${c.s.tx}" opacity=".55" letter-spacing=".3">${this._esc(c.cat.toUpperCase())}</text>`);
        });

        p.push('<g class="wf-edges">');
        T.forEach((t, i) => { p.push(this._svgEdge(t, pos, i)); });
        p.push('</g><g class="wf-nodes">');
        S.forEach(s => { p.push(this._svgNode(s, pos)); });
        p.push('</g></svg>');
        return p.join("\n");
    }

    _svgNode(st, pos) {
        const pp = pos.get(st.id); if (!pp) return "";
        const { x, y } = pp;
        const s = this._s(st.status_category);
        const init = st.state_type === "initial", fin = st.state_type === "final";
        const sw = init || fin ? 2.5 : 1.5;
        const r = [];
        r.push(`<g class="wf-node" data-id="${st.id}">`);
        r.push(`<rect x="${x}" y="${y}" width="${this.NW}" height="${this.NH}" rx="${this.NR}" fill="white" filter="url(#wf-sh)"/>`);
        r.push(`<rect class="wf-node-bg" x="${x}" y="${y}" width="${this.NW}" height="${this.NH}" rx="${this.NR}" fill="${s.bg}" stroke="${s.accent}" stroke-width="${sw}"/>`);
        const iy = y + this.NH / 2;
        if (init) {
            r.push(`<circle cx="${x + 15}" cy="${iy}" r="7" fill="${s.accent}" opacity=".85"/>`);
            r.push(`<polygon points="${x + 13},${iy - 4} ${x + 19},${iy} ${x + 13},${iy + 4}" fill="#fff"/>`);
        } else if (fin) {
            r.push(`<circle cx="${x + 15}" cy="${iy}" r="7" fill="none" stroke="${s.accent}" stroke-width="1.5"/>`);
            r.push(`<circle cx="${x + 15}" cy="${iy}" r="3.5" fill="${s.accent}"/>`);
        }
        const lbl = st.label || st.name;
        const hi = init || fin;
        const tx = hi ? x + 28 : x + this.NW / 2;
        const anch = hi ? "start" : "middle";
        const mc = hi ? 14 : 17;
        const d = lbl.length > mc ? lbl.substring(0, mc - 1) + "…" : lbl;
        r.push(`<text x="${tx}" y="${iy + 4.5}" text-anchor="${anch}" font-size="12.5" font-weight="500" fill="${s.tx}">${this._esc(d)}</text>`);
        r.push(`<title>${this._esc(lbl)} [${st.state_type}]${st.status_category ? " — " + st.status_category : ""}</title>`);
        r.push("</g>");
        return r.join("");
    }

    _svgEdge(t, pos, idx) {
        const f = pos.get(t.from_state_id), o = pos.get(t.to_state_id);
        if (!f || !o) return "";
        if (t.from_state_id === t.to_state_id) return this._svgSelf(f, t);
        const d = this._svgPath(f, o, idx);
        const dash = t.is_automatic ? ' stroke-dasharray="5,3"' : "";
        return `<g class="wf-edge" data-from="${t.from_state_id}" data-to="${t.to_state_id}"><path d="${d}" fill="none" stroke="#9ca3af" stroke-width="1.5" marker-end="url(#wf-arr)"${dash}/><title>${this._esc(t.label || t.name)}</title></g>`;
    }

    _svgPath(f, o, idx) {
        const W = this.NW, H = this.NH, j = ((idx % 7) - 3) * 3.5;
        if (f.col < o.col) {
            const sx = f.x + W, sy = f.y + H / 2 + j, ex = o.x, ey = o.y + H / 2 + j, dx = ex - sx;
            return `M${sx},${sy} C${sx + dx * .38},${sy} ${ex - dx * .38},${ey} ${ex},${ey}`;
        }
        if (f.col === o.col) {
            const dn = f.row < o.row;
            const sy = f.y + (dn ? H - 4 : 4), ey = o.y + (dn ? 4 : H - 4);
            const sx = f.x + W, ex = o.x + W;
            const b = 32 + Math.abs(f.row - o.row) * 18 + (idx % 4) * 7;
            return `M${sx},${sy} C${sx + b},${sy} ${ex + b},${ey} ${ex},${ey}`;
        }
        const sy = f.y + H / 2 + j, ey = o.y + H / 2 + j;
        const bw = Math.max(f.y, o.y) + H + 45 + (idx % 4) * 12;
        return `M${f.x},${sy} C${f.x - 30},${bw} ${o.x + W + 30},${bw} ${o.x + W},${ey}`;
    }

    _svgSelf(p, t) {
        const cx = p.x + this.NW / 2, top = p.y - 8;
        return `<g class="wf-edge" data-from="${t.from_state_id}" data-to="${t.to_state_id}"><path d="M${cx - 14},${top} C${cx - 14},${top - 22} ${cx + 14},${top - 22} ${cx + 14},${top}" fill="none" stroke="#9ca3af" stroke-width="1.5" marker-end="url(#wf-arr)"/><title>${this._esc(t.label || t.name)} (loop)</title></g>`;
    }

    _svgInteract() {
        const svg = this.canvasTarget.querySelector("svg");
        if (!svg) return;
        svg.querySelectorAll(".wf-node").forEach(node => {
            node.style.cursor = "pointer";
            node.addEventListener("mouseenter", () => {
                const id = node.dataset.id;
                svg.classList.add("wf-focus");
                node.classList.add("wf-hi");
                const linked = new Set([id]);
                svg.querySelectorAll(".wf-edge").forEach(e => {
                    if (e.dataset.from === id || e.dataset.to === id) {
                        e.classList.add("wf-hi");
                        linked.add(e.dataset.from); linked.add(e.dataset.to);
                    }
                });
                svg.querySelectorAll(".wf-node").forEach(n => {
                    if (!linked.has(n.dataset.id)) n.classList.add("wf-lo");
                });
            });
            node.addEventListener("mouseleave", () => {
                svg.classList.remove("wf-focus");
                svg.querySelectorAll(".wf-hi,.wf-lo").forEach(el => el.classList.remove("wf-hi", "wf-lo"));
            });
        });
    }

    /* ── utilities ──────────────────────────────────────── */

    _esc(s) {
        return String(s || "").replace(/&/g, "&amp;").replace(/</g, "&lt;")
            .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }
}

if (!window.Controllers) { window.Controllers = {}; }
window.Controllers["workflow-visualizer"] = WorkflowVisualizerController;
