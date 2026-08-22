<?php
require_once __DIR__ . '/../config.php';
function infoIcon($text, $cls = 'w-4 h-4') {
    $safe = htmlspecialchars(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return '<span class="info-icon ' . $cls . '" data-tooltip="' . $safe . '">i</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?= h($pageTitle ?? SITE_NAME) ?> - <?= SITE_NAME ?></title>
    <link rel="icon" type="image/png" href="/logo-nuevo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            400: 'rgb(var(--primary-400-rgb, 96 165 250) / <alpha-value>)',
                            500: 'rgb(var(--primary-500-rgb, 59 130 246) / <alpha-value>)',
                            600: 'rgb(var(--primary-600-rgb, 37 99 235) / <alpha-value>)',
                            700: 'rgb(var(--primary-700-rgb, 30 64 175) / <alpha-value>)',
                            800: 'rgb(var(--primary-800-rgb, 30 58 138) / <alpha-value>)',
                        },
                        surface: {
                            300: 'rgb(var(--surface-300-rgb, 203 213 225) / <alpha-value>)',
                            400: 'rgb(var(--surface-400-rgb, 148 163 184) / <alpha-value>)',
                            500: 'rgb(var(--surface-500-rgb, 107 114 128) / <alpha-value>)',
                            600: 'rgb(var(--surface-600-rgb, 75 85 99) / <alpha-value>)',
                            650: 'rgb(var(--surface-650-rgb, 37 37 48) / <alpha-value>)',
                            700: 'rgb(var(--surface-700-rgb, 26 26 31) / <alpha-value>)',
                            800: 'rgb(var(--surface-800-rgb, 20 20 25) / <alpha-value>)',
                            900: 'rgb(var(--surface-900-rgb, 15 15 20) / <alpha-value>)',
                            950: 'rgb(var(--surface-950-rgb, 11 11 15) / <alpha-value>)',
                        },
                        accent: 'var(--accent, rgb(59 130 246))',
                        'bg-base': 'var(--bg-base, rgb(11 11 15))',
                        'bg-surface': 'var(--bg-surface, rgb(15 15 20))',
                        'bg-elevated': 'var(--bg-elevated, rgb(20 20 25))',
                        'bg-panel': 'var(--bg-panel, rgb(15 15 20))',
                        'bg-input': 'var(--bg-input, rgb(11 11 15))',
                        'text-heading': 'var(--text-heading, #f9fafb)',
                        'text-body': 'var(--text-body, #d1d5db)',
                        'text-muted': 'var(--text-muted, #9ca3af)',
                        'text-subtle': 'var(--text-subtle, #6b7280)',
                        'border-theme': 'var(--border-color, rgba(255,255,255,0.06))',
                        'border-subtle': 'var(--border-subtle, rgba(255,255,255,0.04))',
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
                    },
                    boxShadow: {
                        theme: '0 8px 32px var(--shadow-color, rgba(0,0,0,0.35))',
                        'theme-sm': '0 4px 16px var(--shadow-color, rgba(0,0,0,0.2))',
                    },
                },
            },
        }
    </script>
    <style>
        :root {
            --surface-950: #0b0b0f;
            --surface-900: #0f0f14;
            --surface-800: #141419;
            --surface-700: #1a1a1f;
            --surface-650: #252530;
            --surface-600: #4b5563;
            --surface-500: #6b7280;
            --surface-400: #94a3b8;
            --surface-300: #cbd5e1;
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --primary-700: #1e40af;
            --primary-800: #1e3a8a;
            --primary-400: #60a5fa;
            --primary-500-rgb: 59, 130, 246;
            --primary-400-rgb: 96, 165, 250;
            --primary-600-rgb: 37, 99, 235;
            --success-rgb: 34, 197, 94;
            --warning-rgb: 245, 158, 11;
            --danger-rgb: 239, 68, 68;
            --bg-base: #0b0b0f;
            --bg-surface: #0f0f14;
            --bg-elevated: #141419;
            --bg-panel: #0f0f14;
            --bg-panel-hover: #141419;
            --bg-input: #0b0b0f;
            --bg-overlay: rgba(0,0,0,0.65);
            --border-color: rgba(255,255,255,0.06);
            --border-subtle: rgba(255,255,255,0.04);
            --text-heading: #f9fafb;
            --text-body: #d1d5db;
            --text-muted: #9ca3af;
            --text-subtle: #6b7280;
            --text-placeholder: #4b5563;
            --accent: #3b82f6;
            --accent-rgb: 59 130 246;
            --accent-subtle: rgba(59,130,246,0.12);
            --accent-border: rgba(59,130,246,0.25);
            --accent-hover: #3b82f6;
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #22c55e;
            --info: var(--primary-500);
            --shadow-color: rgba(0,0,0,0.35);
        }
        * { scrollbar-width: thin; scrollbar-color: var(--border-color) transparent; }
        *::-webkit-scrollbar { width: 6px; }
        *::-webkit-scrollbar-track { background: transparent; }
        *::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }
        *::-webkit-scrollbar-thumb:hover { background: var(--surface-650); }

        @keyframes scanline {
            0% { transform: translateY(-100%); }
            100% { transform: translateY(100vh); }
        }
        @keyframes pulse-glow {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.05); }
        }
        @keyframes radar-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @keyframes radar-pulse {
            0% { transform: scale(0.3); opacity: 0.8; }
            100% { transform: scale(2.5); opacity: 0; }
        }
        @keyframes glitch {
            0%, 90%, 100% { transform: translate(0); }
            92% { transform: translate(-1px, 1px); }
            94% { transform: translate(1px, -1px); }
            96% { transform: translate(-1px); }
            98% { transform: translate(1px, 1px); }
        }
        @keyframes border-glow {
            0%, 100% { border-color: rgba(59, 130, 246, 0.2); }
            50% { border-color: rgba(59, 130, 246, 0.5); }
        }
        @keyframes gradient-rotate {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes spin-260 {
            from { transform: rotate(0deg); }
            to { transform: rotate(260deg); }
        }

        .animate-scanline { animation: scanline 8s linear infinite; }
        .animate-pulse-glow { animation: pulse-glow 3s ease-in-out infinite; }
        .animate-radar-spin { animation: radar-spin 12s linear infinite; }
        .animate-radar-pulse { animation: radar-pulse 3s ease-out infinite; }
        .animate-glitch { animation: glitch 0.3s ease-in-out infinite; }
        .animate-border-glow { animation: border-glow 2s ease-in-out infinite; }
        .animate-gradient-rotate { animation: gradient-rotate 3s ease infinite; background-size: 200% 200%; }
        .animate-fade-in-up { animation: fadeInUp 0.5s ease-out; }
        .animate-spin-260 { animation: spin-260 0.25s ease-out; }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            background-color: var(--primary-500);
            color: white;
            font-weight: 500;
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: background-color 0.15s;
            cursor: pointer;
            border: none;
            text-decoration: none;
        }
        .btn-primary:hover { background-color: var(--primary-600); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            color: var(--text-body);
            font-weight: 500;
            font-size: 0.875rem;
            padding: 0.625rem 1rem;
            border-radius: 0.375rem;
            border: 1px solid var(--border-theme);
            transition: all 0.15s;
            cursor: pointer;
        }
        .btn-secondary:hover { background: rgba(255,255,255,0.04); border-color: var(--surface-600); color: white; }

        .btn-danger {
            background-color: rgba(239,68,68,0.1);
            color: rgb(248,113,113);
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid rgba(239,68,68,0.2);
            transition: background-color 0.15s;
            cursor: pointer;
        }
        .btn-danger:hover { background-color: rgba(239,68,68,0.2); }

        .btn-glow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(to right, #06b6d4, #10b981);
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(6,182,212,0.25);
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-glow:hover {
            box-shadow: 0 6px 24px rgba(6,182,212,0.35);
            transform: translateY(-1px);
        }
        .btn-glow:active { transform: translateY(0); }
        .btn-glow:disabled { opacity: 0.5; pointer-events: none; }

        .input-field {
            width: 100%;
            background-color: var(--surface-900);
            border: 1px solid var(--surface-700);
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: white;
            transition: border-color 0.15s;
            outline: none;
        }
        .input-field::placeholder { color: var(--surface-500); }
        .input-field:focus { border-color: var(--primary-500); }

        .input-premium {
            width: 100%;
            background-color: #0f1419;
            border: 1px solid var(--border-theme);
            border-radius: 0.375rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: white;
            transition: border-color 0.15s;
            outline: none;
        }
        .input-premium::placeholder { color: var(--text-subtle); }
        .input-premium:focus { border-color: var(--primary-500); }
        .input-premium:disabled { opacity: 0.5; }

        .glass-card {
            background-color: rgba(15,15,20,0.6);
            border: 1px solid var(--border-theme);
            border-radius: 0.75rem;
            overflow: hidden;
            transition: all 0.3s;
        }
        .glass-card:hover {
            border-color: var(--surface-600);
            background-color: rgba(15,15,20,0.75);
        }

        .label-premium {
            font-size: 12px;
            color: var(--text-body);
            font-weight: 500;
            margin-bottom: 0.375rem;
            display: block;
        }

        .severity-critical { color: rgb(248,113,113); background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); }
        .severity-high { color: rgb(251,146,60); background: rgba(249,115,22,0.1); border: 1px solid rgba(249,115,22,0.2); }
        .severity-medium { color: rgb(250,204,21); background: rgba(234,179,8,0.1); border: 1px solid rgba(234,179,8,0.2); }
        .severity-low { color: rgb(74,222,128); background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); }

        .theme-text-heading { color: var(--text-heading); }
        .theme-text-body { color: var(--text-body); }
        .theme-text-muted { color: var(--text-muted); }
        .theme-text-subtle { color: var(--text-subtle); }
        .theme-text-accent { color: var(--accent); }
        .theme-bg-base { background-color: var(--bg-base); }
        .theme-bg-surface { background-color: var(--bg-surface); }
        .theme-bg-elevated { background-color: var(--bg-elevated); }
        .theme-bg-panel { background-color: var(--bg-panel); }
        .theme-bg-input { background-color: var(--bg-input); }

        .theme-input {
            background-color: var(--bg-input);
            border: 1px solid var(--border-color);
            color: var(--text-heading);
        }
        .theme-input::placeholder { color: var(--text-placeholder); }
        .theme-input:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px var(--accent-subtle);
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-body);
            margin: 0;
            min-height: 100vh;
        }

        .scrollbar-custom::-webkit-scrollbar { width: 4px; }
        .scrollbar-custom::-webkit-scrollbar-track { background: transparent; }
        .scrollbar-custom::-webkit-scrollbar-thumb { background: var(--surface-700); border-radius: 2px; }

        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            padding: 0.75rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
            animation: fadeInUp 0.3s ease-out;
            max-width: 24rem;
        }
        .toast-success { background: #065f46; color: #a7f3d0; border: 1px solid #059669; }
        .toast-error { background: #7f1d1d; color: #fecaca; border: 1px solid #dc2626; }
        .toast-info { background: #1e3a5f; color: #bfdbfe; border: 1px solid #3b82f6; }

        /* React theme matching utilities */
        .cyber-grid {
            background-image:
                linear-gradient(rgba(59, 130, 246, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(59, 130, 246, 0.03) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .cyber-grid-lg {
            background-image:
                linear-gradient(rgba(59, 130, 246, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(59, 130, 246, 0.02) 1px, transparent 1px);
            background-size: 80px 80px;
        }

        .glow-card {
            position: relative;
            transition: all 0.3s ease;
        }
        .glow-card::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, rgba(59,130,246,0), rgba(59,130,246,0.1), rgba(59,130,246,0));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
            transition: opacity 0.3s ease;
            opacity: 0;
        }
        .glow-card:hover::before { opacity: 1; }
        .glow-card:hover { box-shadow: 0 8px 32px rgba(0,0,0,0.3), 0 0 20px rgba(59,130,246,0.08); }

        .gradient-border-card {
            position: relative;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .gradient-border-card::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6, #3b82f6, #06b6d4, #3b82f6);
            background-size: 300% 300%;
            animation: gradient-rotate 4s ease infinite;
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .scan-line { position: relative; overflow: hidden; }
        .scan-line::after {
            content: '';
            position: absolute;
            left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, rgba(59,130,246,0.3), transparent);
            animation: scanline 3s linear infinite;
            pointer-events: none; z-index: 1;
        }

        .glitch-text { animation: glitch 0.3s ease-in-out infinite; }

        .hover-lift {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .hover-lift:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
        }

        .shimmer-bar {
            background-size: 200% auto;
            animation: shimmer 3s ease-in-out infinite;
        }

        .data-line {
            stroke-dasharray: 100;
            animation: data-flow 2s linear infinite;
        }

        .animate-float { animation: float 3s ease-in-out infinite; }
        .animate-chat-open { animation: chat-open 0.2s ease-out; }
        .animate-slide-up { animation: slide-up 0.3s ease-out; }
        .animate-fade-out { animation: fade-out 0.3s ease-out forwards; }

        .control-btn-dashboard {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .control-btn-dashboard::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1), transparent);
            opacity: 0;
            transition: opacity 0.2s;
        }
        .control-btn-dashboard:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 24px rgba(0,0,0,0.25);
        }
        .control-btn-dashboard:hover::before {
            opacity: 1;
        }
        .control-btn-dashboard:active {
            transform: translateY(0) scale(0.98);
        }

        /* Premium device card styling */
        .device-card-premium {
            background: rgba(15, 20, 28, 0.7);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .device-card-premium::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 120px;
            height: 120px;
            background: radial-gradient(circle at top right, rgba(59,130,246,0.05), transparent 70%);
            pointer-events: none;
        }
        .device-card-premium:hover {
            border-color: rgba(255,255,255,0.12);
            box-shadow: 0 16px 40px rgba(0,0,0,0.25);
            transform: translateY(-2px);
        }
        .device-card-premium.online {
            background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, rgba(15,20,28,0.8) 100%);
            border-color: rgba(16,185,129,0.15);
        }
        .device-card-premium.online:hover {
            border-color: rgba(16,185,129,0.3);
            box-shadow: 0 16px 40px rgba(16,185,129,0.1);
        }

        /* Admin panel premium panel headers */
        .admin-panel-header {
            background: linear-gradient(90deg, rgba(59,130,246,0.08), transparent);
            border-left: 3px solid var(--primary-500);
        }

        @keyframes data-flow { 0% { stroke-dashoffset: 100; } 100% { stroke-dashoffset: 0; } }
        @keyframes shimmer { 0% { background-position: -200% center; } 100% { background-position: 200% center; } }
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        @keyframes chat-open { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        @keyframes slide-up { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fade-out { from { opacity: 1; } to { opacity: 0; } }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        select:-webkit-autofill,
        select:-webkit-autofill:hover,
        select:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 30px var(--bg-input) inset !important;
            -webkit-text-fill-color: var(--text-heading) !important;
        }
        select { color-scheme: light dark; background-color: var(--bg-input); color: var(--text-heading); }
        select option { background-color: var(--bg-panel); color: var(--text-heading); }

        .info-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.15em;
            height: 1.15em;
            min-width: 1.15em;
            border-radius: 9999px;
            background: var(--primary-500, #3b82f6);
            color: #fff;
            font: 700 0.62em/1 sans-serif;
            cursor: help;
            margin-left: 0.35em;
            vertical-align: middle;
            position: relative;
            box-shadow: 0 0 0 1.5px rgba(255,255,255,0.08) inset;
        }
        #info-tooltip {
            position: fixed;
            z-index: 99999;
            width: 260px;
            max-width: calc(100vw - 24px);
            padding: 8px 10px;
            background: var(--bg-elevated, #141419);
            color: var(--text-body, #d1d5db);
            border: 1px solid var(--border-theme, rgba(255,255,255,0.06));
            border-radius: 8px;
            font-size: 11px;
            font-weight: 400;
            line-height: 1.4;
            text-align: left;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
            display: none;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.12s, visibility 0.12s;
            pointer-events: none;
        }
        #info-tooltip.visible {
            display: block;
            opacity: 1;
            visibility: visible;
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var tooltip = document.createElement('div');
        tooltip.id = 'info-tooltip';
        document.body.appendChild(tooltip);

        function positionTooltip(icon) {
            var text = icon.getAttribute('data-tooltip');
            if (!text) return;
            tooltip.textContent = text;
            tooltip.classList.add('visible');

            var rect = icon.getBoundingClientRect();
            var margin = 8;
            var vw = window.innerWidth;
            var vh = window.innerHeight;
            var tt = tooltip.getBoundingClientRect();

            // Prefer below the icon
            var top = rect.bottom + margin;
            var left = rect.left + rect.width / 2 - tt.width / 2;

            // If it overflows the right edge, push it back
            if (left + tt.width > vw - margin) {
                left = vw - tt.width - margin;
            }
            // If it overflows the left edge
            if (left < margin) {
                left = margin;
            }

            // If it overflows the bottom, show it above
            if (top + tt.height > vh - margin) {
                top = rect.top - tt.height - margin;
            }

            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
        }

        document.body.addEventListener('mouseenter', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('info-icon')) {
                positionTooltip(e.target);
            }
        }, true);

        document.body.addEventListener('mouseleave', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('info-icon')) {
                tooltip.classList.remove('visible');
            }
        }, true);
    });
    </script>
    <?php require __DIR__ . '/theme.php'; ?>
</head>
<body class="bg-bg-base text-text-body min-h-screen">
<?php if (!empty($_SESSION['flash'])): ?>
    <div class="toast toast-<?= $_SESSION['flash']['type'] ?? 'info' ?>" id="flash-toast">
        <?= h($_SESSION['flash']['message']) ?>
    </div>
    <script>setTimeout(() => { const t = document.getElementById('flash-toast'); if(t) t.remove(); }, 4000);</script>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
