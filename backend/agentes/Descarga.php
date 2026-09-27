<?php
// backend/agentes/Descarga.php

namespace Agentes;

class Descarga
{
    /** GET /api/agents/download/{platform} */
    public static function descargar()
    {
        @set_time_limit(180);
        $usuario = \Auth::requireAuth();
        $token = \get_token();

        $platform = $_GET['platform'] ?? 'win-x64';
        if (preg_match('#^win#i', $platform) || isset($_GET['installer'])) {
            $platform = 'win-x64';
        }
        $allowed = ['win-x64', 'linux-x64', 'mac-x64', 'mac-arm64'];
        if (!in_array($platform, $allowed, true)) $platform = 'win-x64';

        $deployId = $_GET['deploy'] ?? '';
        if ($deployId) {
            $bd = \Database::getInstance();
            $deploy = $bd->findOne('agent_deploys', ['_id' => $deployId, 'userId' => $usuario['_id']]);
            if ($deploy) {
                $bd->updateOne('agent_deploys', ['_id' => $deployId], [
                    '$inc'   => ['downloadCount' => 1],
                    'status' => 'downloaded',
                ]);
            }
        }

        if ($platform === 'win-x64') {
            return self::descargarWindows($usuario, $token, $deployId);
        }

        $binaryMap = [
            'linux-x64' => 'securelab-agent-linux-x64',
            'mac-x64'   => 'securelab-agent-mac-x64',
            'mac-arm64' => 'securelab-agent-mac-arm64',
        ];
        $binaryName = $binaryMap[$platform] ?? ('securelab-agent-' . $platform);

        $candidatos = [
            __DIR__ . '/../agent-bin/' . $binaryName,
            '/var/www/html/agent-bin/' . $binaryName,
            __DIR__ . '/../securelab-agent/' . $binaryName,
        ];
        $binaryPath = null;
        foreach ($candidatos as $p) {
            if (file_exists($p) && filesize($p) > 500000) { $binaryPath = $p; break; }
        }

        if (!$binaryPath || !file_exists($binaryPath)) {
            \json_error('Binario del agente no disponible', 503);
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $binaryName . '"');
        header('Content-Length: ' . filesize($binaryPath));
        readfile($binaryPath);
        exit;
    }

    private static function descargarWindows($usuario, $token, $deployId)
    {
        $agentToken = $token ?: \Auth::createToken($usuario['_id'], [
            'email'    => $usuario['email'] ?? '',
            'purpose'  => 'agent_installation',
            'platform' => 'windows',
        ]);

        $baseUrl = API_BASE_URL;
        $apiBase = rtrim($baseUrl, '/') . '/api/agents';
        $wsBase = preg_replace(
            ['#^https://#', '#^http://#'],
            ['wss://', 'ws://'],
            rtrim($baseUrl, '/')
        ) . '/ws/';

        $templateId = $_GET['template_id'] ?? '';
        $pack = null;
        if ($templateId) {
            $bd = \Database::getInstance();
            $pack = $bd->findOne('compliance_packs', [
                '_id'    => $templateId,
                'userId' => $usuario['_id'],
            ]);
            if ($pack && array_key_exists('active', $pack) && $pack['active'] === false) {
                $pack = null;
            }
            if (!$pack) $templateId = '';
        }

        $cacheFile = sys_get_temp_dir()
                   . '/nsis-cache-'
                   . md5($agentToken . $apiBase . $wsBase . $templateId)
                   . '.exe';

        if (file_exists($cacheFile) && filesize($cacheFile) > 500000 && (time() - filemtime($cacheFile) < 86400)) {
            $downloadName = self::nombreDescarga($templateId, $pack);

            \audit_log('agent_downloaded', [
                'platform'   => 'win-x64',
                'templateId' => $templateId,
                'deployId'   => $deployId,
                'filename'   => $downloadName,
                'fromCache'  => true,
            ], $usuario['_id']);

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . filesize($cacheFile));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            readfile($cacheFile);
            exit;
        }

        // Buscar NSIS y binario
        $nsiCandidates = [
            __DIR__ . '/../../installer/SecureLabAgent.nsi',
            '/var/www/html/installer/SecureLabAgent.nsi',
            '/var/www/asier_ley-main/backend/installer/SecureLabAgent.nsi',
        ];
        $nsiPath = null;
        foreach ($nsiCandidates as $c) { if (file_exists($c)) { $nsiPath = $c; break; } }

        $binCandidates = [
            __DIR__ . '/../../installer/securelab-agent.exe',
            __DIR__ . '/../../agent-bin/securelab-agent-win-x64.exe',
            __DIR__ . '/../../securelab-agent/securelab-agent.exe',
            '/var/www/html/installer/securelab-agent.exe',
            '/var/www/html/agent-bin/securelab-agent-win-x64.exe',
        ];
        $agentExePath = null;
        foreach ($binCandidates as $c) {
            if (file_exists($c) && filesize($c) > 500000) { $agentExePath = $c; break; }
        }

        $finalInstallerPath = null;
        $hasMakensis = false;
        exec('which makensis 2>/dev/null', $whichOut, $whichRet);
        if ($whichRet === 0 && !empty($whichOut[0])) $hasMakensis = true;

        if ($hasMakensis && $nsiPath && $agentExePath) {
            $tmpDir = sys_get_temp_dir() . '/nsis-build-' . uniqid();
            if (mkdir($tmpDir, 0755, true)) {
                $tmpOut = $tmpDir . '/SecureLabAgent-Installer.exe';
                $nsiDir = dirname($nsiPath);
                foreach (['LICENSE.txt', 'installer-logo.bmp', 'installer-small.bmp'] as $aux) {
                    if (file_exists($nsiDir . '/' . $aux)) @copy($nsiDir . '/' . $aux, $tmpDir . '/' . $aux);
                }
                @copy($agentExePath, $tmpDir . '/securelab-agent.exe');
                @copy($nsiPath, $tmpDir . '/SecureLabAgent.nsi');

                $cmd = sprintf(
                    'cd %s && makensis -DAGENT_TOKEN=%s -DAPI_BASE=%s -DWS_URL=%s -DTEMPLATE_ID=%s -DOUTFILE=%s SecureLabAgent.nsi 2>&1',
                    escapeshellarg($tmpDir),
                    escapeshellarg($agentToken),
                    escapeshellarg($apiBase),
                    escapeshellarg($wsBase),
                    escapeshellarg($templateId),
                    escapeshellarg($tmpOut)
                );
                exec($cmd, $makensisOutput, $makensisStatus);

                if ($makensisStatus === 0 && file_exists($tmpOut) && filesize($tmpOut) > 500000) {
                    @copy($tmpOut, $cacheFile);
                    $finalInstallerPath = $cacheFile;
                } else {
                    error_log('[DOWNLOAD] makensis falló (status=' . $makensisStatus . '): ' . implode(' | ', $makensisOutput));
                }
                @array_map('unlink', glob($tmpDir . '/*'));
                @rmdir($tmpDir);
            }
        }

        if (!$finalInstallerPath || !file_exists($finalInstallerPath)) {
            if ($templateId !== '') {
                \json_error(
                    'No se pudo generar el instalador con el pack pre-asignado. ' .
                    'Contacta al administrador (makensis no disponible o falló en el servidor).',
                    503
                );
            }
            $prebuilt = [
                __DIR__ . '/../../installer/SecureLabAgent-Installer.exe',
                __DIR__ . '/../../installer/Output/SecureLabAgent-Setup.exe',
                __DIR__ . '/../../SecureLabAgent-Installer.exe',
                '/var/www/html/installer/output/SecureLabAgent-Installer.exe',
                '/var/www/html/installer/SecureLabAgent-Installer.exe',
            ];
            foreach ($prebuilt as $c) {
                if (file_exists($c) && filesize($c) > 500000) { $finalInstallerPath = $c; break; }
            }
        }

        if (!$finalInstallerPath || !file_exists($finalInstallerPath)) {
            \json_error('Instalador NSIS no disponible en el servidor', 503);
        }

        $downloadName = self::nombreDescarga($templateId, $pack);

        \audit_log('agent_downloaded', [
            'platform'   => 'win-x64',
            'templateId' => $templateId,
            'deployId'   => $deployId,
            'filename'   => $downloadName,
            'fromCache'  => ($finalInstallerPath === $cacheFile),
        ], $usuario['_id']);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($finalInstallerPath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($finalInstallerPath);
        exit;
    }

    private static function nombreDescarga($templateId, $pack): string
    {
        $default = 'SecureLabAgent-Installer.exe';
        if ($templateId === '' || $pack === null) return $default;

        $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $pack['name'] ?? 'pack');
        $slug = trim($slug, '-');
        return $slug !== '' ? ('SecureLabAgent-' . $slug . '.exe') : $default;
    }

    /** GET /api/agents/download-binary?platform=X */
    public static function descargarBinario()
    {
        $platform = $_GET['platform'] ?? 'win-x64';
        if (preg_match('#^win#i', $platform)) $platform = 'win-x64';

        $binaryMap = [
            'win-x64'   => 'securelab-agent-win-x64.exe',
            'linux-x64' => 'securelab-agent-linux-x64',
            'mac-x64'   => 'securelab-agent-mac-x64',
            'mac-arm64' => 'securelab-agent-mac-arm64',
        ];
        $binaryName = $binaryMap[$platform] ?? 'securelab-agent-win-x64.exe';

        $candidatos = [
            __DIR__ . '/../agent-bin/' . $binaryName,
            __DIR__ . '/../installer/securelab-agent.exe',
            __DIR__ . '/../securelab-agent/' . $binaryName,
            __DIR__ . '/../securelab-agent/securelab-agent.exe',
            '/var/www/html/agent-bin/' . $binaryName,
            '/var/www/html/installer/securelab-agent.exe',
        ];
        $binaryPath = null;
        foreach ($candidatos as $p) {
            if (file_exists($p) && filesize($p) > 100000) { $binaryPath = $p; break; }
        }

        if (!$binaryPath) \json_error('Binario no encontrado', 404);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $binaryName . '"');
        header('Content-Length: ' . filesize($binaryPath));
        readfile($binaryPath);
        exit;
    }
}