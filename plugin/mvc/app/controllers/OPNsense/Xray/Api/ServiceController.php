<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * Service control: start / stop / reconfigure / status / log / testconnect.
 *
 * v3.0.0: все service-actions принимают опциональный $uuid инстанса.
 * Если $uuid пустой — действие применяется ко всем инстансам.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass    = '\OPNsense\Xray\General';
    protected static $internalServiceTemplate = 'OPNsense/Xray';
    protected static $internalServiceEnabled  = 'enabled';
    protected static $internalServiceName     = 'xray';

    /**
     * Санитизирует UUID аргумент из URL: оставляет только hex-символы и дефисы.
     */
    private function sanitizeUuid(string $uuid): string
    {
        return preg_replace('/[^0-9a-fA-F\-]/', '', $uuid);
    }

    /**
     * BUG-12 FIX: возвращает реальный статус reconfigure.
     */
    public function reconfigureAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray reconfigure' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));

        if (empty($output)) {
            return ['result' => 'failed', 'message' => 'No response from configd (timeout or service unavailable)'];
        }

        $hasError   = stripos($output, 'ERROR')   !== false
                   || stripos($output, 'failed')  !== false;
        $hasSuccess = stripos($output, 'OK')       !== false
                   || stripos($output, 'disabled') !== false;

        if ($hasError || !$hasSuccess) {
            return ['result' => 'failed', 'message' => $output];
        }

        return ['result' => 'ok', 'message' => $output];
    }

    public function statusAction($uuid = '')
    {
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray status' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $result  = $backend->configdRun($cmd);
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['status' => 'error', 'message' => trim($result)];
    }

    /**
     * GET /api/xray/service/statusAll — статус всех инстансов.
     */
    public function statusAllAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray statusall');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['error' => trim($result)];
    }

    public function startAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray start' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $failed  = empty($output)
                || stripos($output, 'ERROR')  !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function stopAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray stop' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        return [
            'result'  => empty($output) ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function restartAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray restart' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $failed  = empty($output)
                || stripos($output, 'ERROR')  !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * BUG-5 FIX: POST-only — лог содержит чувствительные данные.
     */
    public function logAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = $backend->configdRun('xray log');
        return ['log' => $output];
    }

    /**
     * BUG-5 FIX: POST-only.
     */
    public function xraylogAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $output  = $backend->configdRun('xray xraylog' . ($uuid !== '' ? ' ' . $uuid : ''));
        return ['log' => $output];
    }

    /**
     * E5: POST /api/xray/service/validate[/{uuid}]
     */
    public function validateAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray validate' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $ok      = stripos($output, 'OK')    !== false
                && stripos($output, 'ERROR') === false;
        return [
            'result'  => $ok ? 'ok' : 'failed',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * v4.0.0: POST /api/xray/service/validateConfig — валидация ЧЕРНОВИКА
     * полного config.json из редактора Raw JSON (не сохранённого конфига).
     * Тело: {"config": "<json-текст>"}. Файл кладётся в /tmp/xray-val-*
     * и проверяется через configd action "xray validatefile".
     */
    public function validateConfigAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);
        $draft = is_array($data) ? (string)($data['config'] ?? '') : '';
        if ($draft === '') {
            return ['result' => 'failed', 'message' => 'Config is empty'];
        }

        // v4.0.1: xray принимает комментарии (//, #, блочные), а json_decode —
        // нет; pre-check делается по strip-версии, в temp-файл уходит ОРИГИНАЛ
        // (валидирует его сам xray -test)
        $stripped = \OPNsense\Xray\ConfigBuilder::stripJsonComments($draft);
        if (json_decode($stripped, true) === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['result' => 'failed', 'message' => 'Invalid JSON: ' . json_last_error_msg()];
        }

        // v4.0.6: xray определяет формат конфига по РАСШИРЕНИЮ файла —
        // безымянный tempnam давал «core: Failed to get format of /tmp/xray-val-*»
        $tmp = tempnam('/tmp', 'xray-val-');
        if ($tmp === false) {
            return ['result' => 'failed', 'message' => 'Cannot create temp file'];
        }
        $path = $tmp . '.json';
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return ['result' => 'failed', 'message' => 'Cannot create temp file'];
        }
        file_put_contents($path, $draft);
        chmod($path, 0600);

        try {
            $backend = new Backend();
            $output  = trim($backend->configdRun('xray validatefile ' . $path));
        } finally {
            @unlink($path);
        }

        $ok = stripos($output, 'OK')    !== false
           && stripos($output, 'ERROR') === false;

        // v4.0.4: ловушки raw-конфига, которые xray -test не ловит (он не
        // стартует inbound'ы): несуществующий listen-адрес, имя tun не tunN
        if ($ok) {
            $warnings = \OPNsense\Xray\ConfigBuilder::rawConfigIssues($draft);
            if ($warnings) {
                $output .= "\n\nWARNING: xray -test passed, but the config will fail at start:\n"
                    . implode("\n", $warnings);
            }
        }

        return [
            'result'  => $ok ? 'ok' : 'failed',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * v4.1.0: POST /api/xray/service/clientLink — клиентская share-ссылка
     * (vless:// vmess:// trojan:// ss://) для QR/импорта. Строится из ПОЛЕЙ
     * ДИАЛОГА (не из config.xml), поэтому работает на несохранённом черновике.
     * Тело: {"fields": {srv_protocol, srv_transport, ...}, "user": {…}}.
     */
    public function clientLinkAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $body   = json_decode((string)file_get_contents('php://input'), true);
        $fields = is_array($body) && is_array($body['fields'] ?? null) ? $body['fields'] : [];
        $user   = is_array($body) && is_array($body['user'] ?? null) ? $body['user'] : null;
        if ($user === null) {
            return ['result' => 'failed', 'message' => 'Missing "user" object in request body'];
        }

        // дефолты как в parseInstance — затем перекрываем полями диалога
        $inst = [
            'srv_protocol'   => 'vless',
            'srv_listen'     => '127.0.0.1',
            'srv_port'       => 10086,
            'srv_transport'  => 'ws',
            'srv_path'       => '',
            'srv_host'       => '',
            'srv_security'   => 'none',
            'srv_ss_method'  => 'aes-256-gcm',
            'srv_ss_key'     => '',
            'srv_public_host' => '',
            'srv_public_port' => 0,
            'srv_tls_cert'   => '',
            'srv_tls_key'    => '',
            'srv_reality_privatekey' => '',
            'srv_reality_shortids'   => '',
            'srv_reality_servernames' => '',
            'srv_reality_dest' => '',
        ];
        foreach ($inst as $k => $def) {
            if (array_key_exists($k, $fields) && (is_scalar($fields[$k]) || $fields[$k] === null)) {
                $inst[$k] = (string)$fields[$k];
            }
        }
        $inst['srv_port'] = (int)$inst['srv_port'];
        $inst['srv_public_port'] = (int)$inst['srv_public_port'];

        try {
            $link = \OPNsense\Xray\ConfigBuilder::clientShareLink($inst, $user);
            return ['result' => 'ok', 'name' => $link['name'], 'uri' => $link['uri']];
        } catch (\Exception $e) {
            return ['result' => 'failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * v4.0.0: POST /api/xray/service/previewConfig/{uuid} — сгенерированный
     * config.json инстанса (кнопка Preview Generated Config). Сборка
     * происходит в процессе через ConfigBuilder, без смены файла на диске.
     */
    public function previewConfigAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid = $this->sanitizeUuid((string)$uuid);
        if ($uuid === '') {
            return ['result' => 'failed', 'message' => 'Instance UUID required'];
        }
        try {
            $config = \OPNsense\Xray\ConfigBuilder::buildForInstance($uuid);
            return [
                'result' => 'ok',
                'config' => \OPNsense\Xray\ConfigBuilder::render($config),
            ];
        } catch (\Exception $e) {
            return ['result' => 'failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * GET /api/xray/service/version
     */
    public function versionAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray version');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['version' => 'unknown'];
    }

    /**
     * E4: GET /api/xray/service/diagnostics[/{uuid}]
     */
    public function diagnosticsAction($uuid = '')
    {
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray ifstats' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        if (empty($output)) {
            return ['error' => 'No response from configd'];
        }
        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON from ifstats: ' . $output];
        }
        return $data;
    }

    /**
     * I8: POST /api/xray/service/testconnect[/{uuid}]
     */
    public function testconnectAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray testconnect' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));

        $httpCode = (int)$output;
        if ($httpCode >= 200 && $httpCode < 400) {
            return [
                'result'    => 'ok',
                'http_code' => $httpCode,
                'message'   => "OK ({$httpCode}) — connection works",
            ];
        }

        return [
            'result'    => 'failed',
            'http_code' => $httpCode,
            'message'   => $httpCode === 0
                ? 'Could not connect — xray-core may be stopped or port unreachable'
                : "HTTP {$httpCode} — unexpected response from 1.1.1.1",
        ];
    }
}
