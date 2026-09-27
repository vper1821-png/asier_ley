<?php
// backend/agentes/InstaladorLinux.php

namespace Agentes;

class InstaladorLinux
{
    /** GET|POST /api/agents/install/linux */
    public static function ejecutar()
    {
        $usuario = \Auth::requireAuth();
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $token = \Auth::createToken($usuario['_id'], [
            'email'    => $usuario['email'] ?? '',
            'purpose'  => 'agent_installation',
            'platform' => 'linux',
        ]);

        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        $baseUrl = 'https://' . $host;
        $apiBase = rtrim($baseUrl, '/') . '/api/agents';
        $wsBase = 'wss://' . $host . '/ws/';
        $scriptUrl = rtrim($baseUrl, '/') . '/api/agents/install/linux?token=' . urlencode($token);

        if ($metodo === 'POST') {
            \json_response([
                'success'   => true,
                'token'     => $token,
                'scriptUrl' => $scriptUrl,
                'command'   => "curl -k -fsSL '{$scriptUrl}' | sudo bash",
            ]);
        }

        @set_time_limit(180);

        $downloadUrl = rtrim($baseUrl, '/') . '/api/agents/download/linux-x64?token=' . urlencode($token);

        $escapedToken   = json_encode($token);
        $escapedApiBase = json_encode($apiBase);
        $escapedWsBase  = json_encode($wsBase);

        $script = strtr(<<<'TMPL'
#!/bin/bash
set -e

# SecureLab Agent - Instalador Linux
TOKEN=__TOKEN__
API_BASE=__API_BASE__
WS_URL=__WS_URL__
DOWNLOAD_URL=__DOWNLOAD_URL__

INSTALL_DIR="/opt/securelab-agent"
LOG_DIR="/var/log/securelab-agent"
SERVICE_NAME="securelab-agent"

echo "[SecureLab] Instalando agente en ${INSTALL_DIR}..."
mkdir -p "${INSTALL_DIR}" "${LOG_DIR}"

echo "[SecureLab] Descargando binario..."
curl -k -fsSL "${DOWNLOAD_URL}" -o "${INSTALL_DIR}/securelab-agent-linux-x64"
chmod +x "${INSTALL_DIR}/securelab-agent-linux-x64"

cat > "${INSTALL_DIR}/config.json" <<'EOF'
{
  "token": __ESCAPED_TOKEN__,
  "api_base": __ESCAPED_API_BASE__,
  "ws_url": __ESCAPED_WS_BASE__,
  "log_file": "/var/log/securelab-agent/agent.log",
  "log_level": "info",
  "audit_db_path": "/var/lib/securelab-agent/audit.db",
  "knowledge_db_path": "/var/lib/securelab-agent/knowledge.db",
  "state_file": "/var/lib/securelab-agent/.agent-state.json",
  "file_watch_dirs": ["/opt", "/var/www", "/home", "/srv"],
  "persistence_mode": "aggressive",
  "hardening_enabled": false
}
EOF

mkdir -p /var/lib/securelab-agent

SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
echo "[SecureLab] Configurando servicio systemd..."

cat > "${SERVICE_FILE}" <<EOF
[Unit]
Description=SecureLab Agent
After=network.target

[Service]
Type=simple
ExecStart=${INSTALL_DIR}/securelab-agent-linux-x64
Restart=always
RestartSec=5
User=root
WorkingDirectory=${INSTALL_DIR}

[Install]
WantedBy=multi-user.target
EOF

if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload
    systemctl enable --now "${SERVICE_NAME}"
    echo "[SecureLab] Servicio iniciado y habilitado."
    systemctl status "${SERVICE_NAME}" --no-pager
else
    echo "[SecureLab] systemctl no disponible. Ejecutando con nohup..."
    nohup "${INSTALL_DIR}/securelab-agent-linux-x64" > "${LOG_DIR}/agent.log" 2>&1 &
    echo "[SecureLab] PID: $!"
fi

echo "[SecureLab] Instalación completa."
TMPL, [
            '__TOKEN__'              => escapeshellarg($token),
            '__API_BASE__'           => escapeshellarg($apiBase),
            '__WS_URL__'             => escapeshellarg($wsBase),
            '__DOWNLOAD_URL__'       => escapeshellarg($downloadUrl),
            '__ESCAPED_TOKEN__'      => $escapedToken,
            '__ESCAPED_API_BASE__'   => $escapedApiBase,
            '__ESCAPED_WS_BASE__'    => $escapedWsBase,
        ]);

        header('Content-Type: text/x-shellscript; charset=utf-8');
        header('Content-Disposition: attachment; filename="securelab-agent-install.sh"');
        echo $script;
        exit;
    }
}