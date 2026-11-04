<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

/**
 * v4.0.0: CRUD правил маршрутизации xray-core (RoutingRule model).
 * Один контроллер на ArrayField rule; фильтр по инстансу — как
 * GroupController::searchServerAction фильтрует серверы по группе.
 */
class RuleController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\RoutingRule';
    protected static $internalModelName  = 'rule';

    public function searchItemAction()
    {
        $instance = $this->request->getPost('instance', 'string', '');
        $filter = function ($record) use ($instance) {
            return $instance === '' || (string)$record->instance === $instance;
        };
        $response = $this->searchBase(
            'rule',
            ['enabled', 'instance', 'name', 'type', 'value', 'action'],
            'name',
            $filter
        );

        // Подставляем человекочитаемое имя инстанса
        if (!empty($response['rows'])) {
            $names = [];
            $instModel = new \OPNsense\Xray\Instance();
            foreach ($instModel->instance->iterateItems() as $uuid => $i) {
                $names[(string)$uuid] = (string)$i->name;
            }
            foreach ($response['rows'] as &$row) {
                $row['instance_name'] = $names[$row['instance']] ?? $row['instance'];
            }
            unset($row);
        }
        return $response;
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('rule', $uuid, $enabled);
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase('rule', 'rule', $uuid);
    }

    public function addItemAction()
    {
        return $this->addBase('rule', 'rule');
    }

    public function setItemAction($uuid)
    {
        return $this->setBase('rule', 'rule', $uuid);
    }

    public function delItemAction($uuid)
    {
        return $this->delBase('rule', $uuid);
    }
}
