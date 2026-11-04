<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\Instance';
    protected static $internalModelName  = 'instance';

    public function searchItemAction()
    {
        $response = $this->searchBase('instance', ['enabled', 'name', 'outbound_config', 'node_source', 'server', 'tun_driver']);
        if (!empty($response['rows'])) {
            // v3.2.0: в режиме group узел живёт в модели групп, а не в outbound_config
            $servers = null;
            foreach ($response['rows'] as &$row) {
                $mode = (string)($row['node_source'] ?? 'config');
                $row['server_address'] = '';
                $row['server_port']    = '';

                if ($mode === 'raw' || $mode === 'none') {
                    // v4.0.0: raw — полный кастомный конфиг; none — только сервер
                    $row['server_address'] = $mode === 'raw' ? '(raw config)' : '(server mode)';
                    unset($row['outbound_config'], $row['server']);
                    continue;
                }

                $raw = $row['outbound_config'] ?? '';
                $server = (string)($row['server'] ?? '');
                if ($server !== '' && $mode !== 'config') {
                    if ($servers === null) {
                        $servers = [];
                        $groups = new \OPNsense\Xray\Group();
                        foreach ($groups->server->iterateItems() as $uuid => $srv) {
                            $servers[$uuid] = (string)$srv->outbound_config;
                        }
                    }
                    $raw = $servers[$server] ?? '';
                }
                $ob    = json_decode($raw, true);
                $vnext = $ob['settings']['vnext'][0] ?? [];
                $row['server_address'] = $vnext['address'] ?? '';
                $row['server_port']    = isset($vnext['port']) ? (string)$vnext['port'] : '';
                unset($row['outbound_config'], $row['server']);
            }
            unset($row);
        }
        return $response;
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('instance', $uuid, $enabled);
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase('instance', 'instance', $uuid);
    }

    public function addItemAction()
    {
        return $this->addBase('instance', 'instance');
    }

    public function setItemAction($uuid)
    {
        return $this->setBase('instance', 'instance', $uuid);
    }

    public function delItemAction($uuid)
    {
        return $this->delBase('instance', $uuid);
    }
}
