<script>
(function () {
    'use strict';
    const KEY = 'invisia_theme';

    function hexToRgb(hex) {
        const clean = hex.replace('#', '');
        const bigint = parseInt(clean, 16);
        return { r: (bigint >> 16) & 255, g: (bigint >> 8) & 255, b: bigint & 255 };
    }
    function rgbString(c) { return c.r + ' ' + c.g + ' ' + c.b; }
    function alpha(hex, a) { const c = hexToRgb(hex); return 'rgba(' + c.r + ', ' + c.g + ', ' + c.b + ', ' + a + ')'; }
    function isLight(hex) {
        const c = hexToRgb(hex);
        return (0.299 * c.r + 0.587 * c.g + 0.114 * c.b) / 255 > 0.55;
    }

    function buildSemantic(base, primary, options) {
        options = options || {};
        const light = isLight(base);
        return {
            '--bg-base': base,
            '--bg-surface': light ? (options.surfaceLight || '#f1f5f9') : (options.surfaceDark || base),
            '--bg-elevated': light ? (options.elevatedLight || '#f8fafc') : (options.elevatedDark || '#141419'),
            '--bg-panel': options.panel || (light ? '#ffffff' : '#0f0f14'),
            '--bg-panel-hover': options.panelHover || (light ? '#f8fafc' : '#141419'),
            '--bg-input': options.inputBg || (light ? '#f8fafc' : '#0b0b0f'),
            '--bg-overlay': options.overlay || (light ? 'rgba(0,0,0,0.35)' : 'rgba(0,0,0,0.65)'),
            '--border-color': options.border || (light ? 'rgba(0,0,0,0.08)' : 'rgba(255,255,255,0.06)'),
            '--border-subtle': options.borderSubtle || (light ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.04)'),
            '--text-heading': light ? '#111827' : '#f9fafb',
            '--text-body': light ? '#374151' : '#d1d5db',
            '--text-muted': light ? '#6b7280' : '#9ca3af',
            '--text-subtle': light ? '#9ca3af' : '#6b7280',
            '--text-placeholder': light ? '#9ca3af' : '#4b5563',
            '--accent': primary,
            '--accent-rgb': rgbString(hexToRgb(primary)),
            '--accent-subtle': alpha(primary, 0.12),
            '--accent-border': alpha(primary, 0.25),
            '--accent-hover': options.accentHover || primary,
            '--success': options.success || (light ? '#16a34a' : '#22c55e'),
            '--warning': options.warning || (light ? '#d97706' : '#f59e0b'),
            '--danger': options.danger || (light ? '#dc2626' : '#ef4444'),
            '--info': options.info || primary,
            '--shadow-color': options.shadow || (light ? 'rgba(0,0,0,0.08)' : 'rgba(0,0,0,0.35)'),
        };
    }

    function preset(name, label, surfaces, primaries, semanticOpts) {
        return {
            name: name,
            label: label,
            colors: Object.assign({
                '--surface-950': surfaces[0],
                '--surface-900': surfaces[1],
                '--surface-800': surfaces[2],
                '--surface-700': surfaces[3],
                '--surface-650': surfaces[4],
                '--primary-500': primaries[0],
                '--primary-600': primaries[1],
                '--primary-700': primaries[2],
                '--primary-800': primaries[3],
                '--primary-400': primaries[4],
            }, buildSemantic(surfaces[0], primaries[0], semanticOpts)),
        };
    }

    const BLUE = ['#3b82f6', '#2563eb', '#1e40af', '#1e3a8a', '#60a5fa'];
    const lightOpts = {
        panelHover: '#f8fafc', border: 'rgba(0,0,0,0.08)', borderSubtle: 'rgba(0,0,0,0.05)',
        inputBg: '#ffffff', overlay: 'rgba(0,0,0,0.25)', shadow: 'rgba(0,0,0,0.08)',
        success: '#16a34a', warning: '#d97706', danger: '#dc2626', panel: '#ffffff', elevatedLight: '#ffffff',
    };

    const PRESETS = [
        preset('invisia-dark', 'Invisia Dark', ['#0b0b0f', '#0f0f14', '#141419', '#1a1a1f', '#252530'], BLUE),
        preset('pure-black', 'Negro Puro', ['#000000', '#050505', '#0a0a0a', '#111111', '#1a1a1a'], BLUE),
        preset('midnight-blue', 'Azul Medianoche', ['#070b15', '#0a1020', '#0f1729', '#1a2438', '#253349'], BLUE, { surfaceDark: '#0a1020', elevatedDark: '#0f1729', panel: '#0a1020' }),
        preset('royal-purple', 'Púrpura Real', ['#0b0715', '#100a1f', '#1a102e', '#251a3d', '#332852'], ['#a855f7', '#9333ea', '#7e22ce', '#6b21a8', '#c084fc'], { surfaceDark: '#100a1f', elevatedDark: '#1a102e', panel: '#100a1f' }),
        preset('matrix-green', 'Matrix Verde', ['#0a0f0a', '#0d140d', '#121d12', '#1a2a1a', '#253a25'], ['#22c55e', '#16a34a', '#15803d', '#166534', '#4ade80'], { surfaceDark: '#0d140d', elevatedDark: '#121d12', panel: '#0d140d' }),
        preset('sunset-orange', 'Atardecer Naranja', ['#0f0b07', '#1a100a', '#261810', '#332218', '#402d22'], ['#f97316', '#ea580c', '#c2410c', '#9a3412', '#fb923c'], { surfaceDark: '#1a100a', elevatedDark: '#261810', panel: '#1a100a' }),
        preset('crimson-red', 'Carmesí', ['#0f0707', '#1a0a0a', '#261010', '#331818', '#402222'], ['#ef4444', '#dc2626', '#b91c1c', '#991b1b', '#f87171'], { surfaceDark: '#1a0a0a', elevatedDark: '#261010', panel: '#1a0a0a' }),
        preset('cyberpunk', 'Cyberpunk', ['#0a0a12', '#0e0e1f', '#161630', '#1f1f40', '#2a2a52'], ['#22d3ee', '#06b6d4', '#0891b2', '#0e7490', '#67e8f9'], { surfaceDark: '#0e0e1f', elevatedDark: '#161630', panel: '#0e0e1f' }),
        preset('deep-forest', 'Bosque Profundo', ['#080c08', '#0c140c', '#121e12', '#1a2a1a', '#253a25'], ['#84cc16', '#65a30d', '#4d7c0f', '#3f6212', '#a3e635'], { surfaceDark: '#0c140c', elevatedDark: '#121e12', panel: '#0c140c' }),
        preset('ocean-teal', 'Océano Teal', ['#070c0f', '#0a141a', '#0f1f26', '#1a2e36', '#253d47'], ['#14b8a6', '#0d9488', '#0f766e', '#115e59', '#2dd4bf'], { surfaceDark: '#0a141a', elevatedDark: '#0f1f26', panel: '#0a141a' }),
        preset('light', 'Claro', ['#f8fafc', '#f1f5f9', '#e2e8f0', '#cbd5e1', '#94a3b8'], BLUE, Object.assign({}, lightOpts, { surfaceLight: '#f1f5f9', panelHover: '#f8fafc' })),
        preset('light-amber', 'Ámbar Claro', ['#fffbeb', '#fef3c7', '#fde68a', '#fcd34d', '#fbbf24'], ['#f59e0b', '#d97706', '#b45309', '#92400e', '#fbbf24'], Object.assign({}, lightOpts, { surfaceLight: '#fef3c7', panelHover: '#fffbeb' })),
    ];

    const CUSTOM_VARS = [
        { v: '--primary-500', label: 'Primario' },
        { v: '--primary-600', label: 'Primario Oscuro' },
        { v: '--surface-800', label: 'Superficie' },
        { v: '--surface-700', label: 'Bordes' },
        { v: '--surface-650', label: 'Hover' },
        { v: '--success', label: 'Éxito' },
        { v: '--warning', label: 'Advertencia' },
        { v: '--danger', label: 'Peligro' },
        { v: '--text-heading', label: 'Texto' },
    ];

    function loadTheme() {
        try {
            const raw = localStorage.getItem(KEY);
            if (raw) return JSON.parse(raw);
        } catch (e) {}
        return { preset: 'invisia-dark', custom: {} };
    }
    function saveTheme(t) {
        try { localStorage.setItem(KEY, JSON.stringify(t)); } catch (e) {}
    }
    function getPreset(name) {
        return PRESETS.find(p => p.name === name) || PRESETS[0];
    }
    function resolvedColors(t) {
        return Object.assign({}, getPreset(t.preset).colors, t.custom || {});
    }
    function applyTheme(t) {
        const root = document.documentElement;
        const all = resolvedColors(t);
        for (const v in all) {
            root.style.setProperty(v, all[v]);
            if (typeof all[v] === 'string' && all[v].charAt(0) === '#') {
                const c = hexToRgb(all[v]);
                root.style.setProperty(v + '-rgb', c.r + ' ' + c.g + ' ' + c.b);
            }
        }
        root.setAttribute('data-theme', t.preset);
    }

    let theme = loadTheme();
    applyTheme(theme);

    // ── Popup UI ──
    let popupEl = null;

    function swatches(p) {
        return ['--primary-500', '--surface-700', '--surface-800', '--surface-950']
            .map(v => '<span class="inline-block w-4 h-4 rounded-sm" style="background:' + p.colors[v] + '"></span>')
            .join('');
    }

    function renderPopup() {
        if (!popupEl) return;
        const cur = getPreset(theme.preset);
        const res = resolvedColors(theme);
        popupEl.innerHTML =
            '<div class="flex items-center justify-between px-4 py-3 border-b border-border-theme">' +
                '<p class="text-[12px] font-semibold" style="color:var(--text-heading)">Personalizar tema</p>' +
                '<button data-th-close class="text-text-subtle hover:text-text-heading"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>' +
            '</div>' +
            '<div class="flex-1 overflow-y-auto scrollbar-custom p-4 space-y-4">' +
                '<div>' +
                    '<p class="text-[10px] font-medium text-text-subtle uppercase tracking-wider mb-2">Temas predefinidos</p>' +
                    '<div class="grid grid-cols-2 gap-1.5">' +
                    PRESETS.map(p =>
                        '<button data-th-preset="' + p.name + '" class="flex items-center gap-2 px-2.5 py-2 rounded-lg border text-left transition-all ' +
                        (p.name === theme.preset ? 'border-primary-500 bg-primary-600/10' : 'border-border-theme hover:border-surface-600') + '">' +
                            '<span class="flex gap-px shrink-0">' + swatches(p) + '</span>' +
                            '<span class="text-[10px] font-medium truncate" style="color:var(--text-body)">' + p.label + '</span>' +
                        '</button>'
                    ).join('') +
                    '</div>' +
                '</div>' +
                '<div>' +
                    '<div class="flex items-center justify-between mb-2">' +
                        '<p class="text-[10px] font-medium text-text-subtle uppercase tracking-wider">Colores personalizados</p>' +
                        '<button data-th-reset class="text-[10px] text-primary-400 hover:text-primary-300">Restablecer</button>' +
                    '</div>' +
                    '<div class="space-y-1.5">' +
                    CUSTOM_VARS.map(cv => {
                        let val = res[cv.v] || '#000000';
                        if (val.charAt(0) !== '#') val = '#000000';
                        return '<label class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg border border-border-theme cursor-pointer hover:border-surface-600 transition-all">' +
                            '<span class="text-[11px]" style="color:var(--text-body)">' + cv.label + '</span>' +
                            '<input type="color" data-th-var="' + cv.v + '" value="' + val + '" class="w-8 h-6 rounded cursor-pointer bg-transparent border-0 p-0">' +
                        '</label>';
                    }).join('') +
                    '</div>' +
                '</div>' +
            '</div>';

        popupEl.querySelector('[data-th-close]').addEventListener('click', closeThemePopup);
        popupEl.querySelectorAll('[data-th-preset]').forEach(btn => {
            btn.addEventListener('click', function () {
                theme = { preset: this.getAttribute('data-th-preset'), custom: {} };
                saveTheme(theme);
                applyTheme(theme);
                renderPopup();
                updateThemeLabel();
            });
        });
        popupEl.querySelector('[data-th-reset]').addEventListener('click', function () {
            theme.custom = {};
            saveTheme(theme);
            applyTheme(theme);
            renderPopup();
        });
        popupEl.querySelectorAll('[data-th-var]').forEach(inp => {
            inp.addEventListener('input', function () {
                theme.custom = theme.custom || {};
                theme.custom[this.getAttribute('data-th-var')] = this.value;
                saveTheme(theme);
                applyTheme(theme);
            });
        });
    }

    function updateThemeLabel() {
        const label = document.getElementById('theme-label');
        if (label) label.textContent = getPreset(theme.preset).label;
        const dot = document.getElementById('theme-dot');
        if (dot) {
            const c = resolvedColors(theme)['--primary-500'];
            dot.style.backgroundColor = c;
            dot.style.boxShadow = '0 0 6px ' + c + '99';
        }
    }

    window.toggleThemePopup = function () {
        if (!popupEl) return;
        if (popupEl.classList.contains('hidden')) {
            renderPopup();
            popupEl.classList.remove('hidden');
        } else {
            popupEl.classList.add('hidden');
        }
    };
    window.closeThemePopup = function () {
        if (popupEl) popupEl.classList.add('hidden');
    };

    document.addEventListener('DOMContentLoaded', function () {
        popupEl = document.createElement('div');
        popupEl.id = 'theme-popup';
        popupEl.className = 'hidden fixed bottom-4 left-4 md:left-60 z-[120] w-80 max-h-[80vh] rounded-xl border border-border-theme bg-bg-panel shadow-2xl flex flex-col overflow-hidden';
        document.body.appendChild(popupEl);
        updateThemeLabel();

        document.addEventListener('mousedown', function (e) {
            if (!popupEl.classList.contains('hidden') && !popupEl.contains(e.target) && !e.target.closest('.tour-theme-btn')) {
                popupEl.classList.add('hidden');
            }
        });
    });
})();
</script>
