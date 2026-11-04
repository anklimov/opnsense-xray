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
        // v4.3.0: при фильтре по инстансу показываем и общие правила (instance = '')
        $filter = function ($record) use ($instance) {
            $recInst = (string)$record->instance;
            return $instance === '' || $recInst === $instance || $recInst === '';
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
                $row['instance_name'] = ($row['instance'] === '')
                    ? '(all)'
                    : ($names[$row['instance']] ?? $row['instance']);
            }
            unset($row);
        }
        return $response;
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('rule', $uuid, $enabled);
    }

    /**
     * v4.3.0: POST /api/xray/rule/sortRules — порядок после drag&drop.
     * Тело: order[] = uuid видимых строк grid в новом порядке (splice-
     * семантика на сервере: вырезать posted и вставить на место первого).
     */
    public function sortRulesAction()
    {
        if (!$this->request->isPost() || !$this->request->hasPost('order')) {
            return ['status' => 'error', 'message' => 'POST order[] required'];
        }
        $order = $this->request->getPost('order');
        if (!is_array($order)) {
            $order = [$order];
        }
        try {
            $ok = \OPNsense\Xray\ConfigBuilder::reorderRules($order);
            return ['status' => $ok ? 'ok' : 'error'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
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
