<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicepterodactyl\Api;

class Admin extends \Api_Abstract
{
    /**
     * Get global Pterodactyl settings.
     *
     * @return array
     */
    public function get_settings(): array
    {
        $systemService = $this->di['mod_service']('system');
        
        return [
            'panel_url' => $systemService->getParamValue('servicepterodactyl_panel_url', ''),
            'api_key' => $systemService->getParamValue('servicepterodactyl_api_key', ''),
            'sso_secret' => $systemService->getParamValue('servicepterodactyl_sso_secret', ''),
            'client_api_key' => $systemService->getParamValue('servicepterodactyl_client_api_key', ''),
            'allowed_nodes' => json_decode($systemService->getParamValue('servicepterodactyl_allowed_nodes', '[]'), true),
            'default_node' => (int) $systemService->getParamValue('servicepterodactyl_default_node', 0),
            'node_allocation_map' => json_decode($systemService->getParamValue('servicepterodactyl_node_allocation_map', '{}'), true),
        ];
    }

    /**
     * Save global Pterodactyl settings.
     *
     * @param array $data
     * @return bool
     */
    public function save_settings(array $data): bool
    {
        $systemService = $this->di['mod_service']('system');
        
        if (isset($data['panel_url'])) {
            $systemService->setParamValue('servicepterodactyl_panel_url', $data['panel_url']);
        }
        
        if (isset($data['api_key'])) {
            $systemService->setParamValue('servicepterodactyl_api_key', $data['api_key']);
        }

        if (isset($data['sso_secret'])) {
            $systemService->setParamValue('servicepterodactyl_sso_secret', $data['sso_secret']);
        }
        
        if (isset($data['client_api_key'])) {
            $systemService->setParamValue('servicepterodactyl_client_api_key', $data['client_api_key']);
        }
        
        if (isset($data['allowed_nodes'])) {
            $systemService->setParamValue('servicepterodactyl_allowed_nodes', json_encode($data['allowed_nodes']));
        }
        
        if (isset($data['default_node'])) {
            $systemService->setParamValue('servicepterodactyl_default_node', (int) $data['default_node']);
        }
        
        if (isset($data['node_allocation_map']) && is_array($data['node_allocation_map'])) {
            // Sanitize: ensure host and ports are proper types
            $map = [];
            foreach ($data['node_allocation_map'] as $nodeId => $entry) {
                $host = isset($entry['host']) ? trim($entry['host']) : '';
                $port_start = isset($entry['port_start']) ? (int)$entry['port_start'] : null;
                $port_end = isset($entry['port_end']) ? (int)$entry['port_end'] : null;
                if ($host !== '' || $port_start !== null || $port_end !== null) {
                    $map[(int)$nodeId] = [
                        'host' => $host,
                        'port_start' => $port_start,
                        'port_end' => $port_end,
                    ];
                }
            }
            $systemService->setParamValue('servicepterodactyl_node_allocation_map', json_encode($map));
        }
        
        return true;
    }

    /**
     * Get all nodes from Pterodactyl.
     *
     * @return array
     */
    public function get_nodes(): array
    {
        return $this->getService()->getNodes();
    }

    /**
     * Get all locations from Pterodactyl.
     *
     * @return array
     */
    public function get_locations(): array
    {
        return $this->getService()->getLocations();
    }

    /**
     * Get all eggs from Pterodactyl.
     *
     * @return array
     */
    public function get_eggs(): array
    {
        return $this->getService()->getEggs();
    }

    /**
     * Get egg details (including variables).
     *
     * @param array $data
     * @return array
     */
    public function get_egg_details($data): array
    {
        if (empty($data['egg_id'])) {
            return [];
        }
        return $this->getService()->getEggInfo((int)$data['egg_id']);
    }

    /**
     * Update a Pterodactyl server. Can be used to change the config.
     *
     * @param array $data - An associative array
     *                    - int 'order_id' (required) The order ID of the server to update.
     *                    - array 'config' (optional) The new configuration for the server.
     */
    public function update($data): bool
    {
        return $this->getService()->updateServer($data);
    }

    /**
     * Provision a new Pterodactyl server.
     *
     * @param array $data - An associative array
     *                    - int 'order_id' (required) The order ID to provision.
     */
    public function provision($data): bool
    {
        if (empty($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required for provisioning.');
        }

        $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        
        return $this->getService()->provision($order, $model);
    }

    /**
     * Unprovision a Pterodactyl server.
     *
     * @param array $data - An associative array
     *                    - int 'order_id' (required) The order ID to unprovision.
     */
    public function unprovision($data): bool
    {
        if (empty($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required for unprovisioning.');
        }

        $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        
        $this->getService()->unprovision($order, $model);
        return true;
    }

    /**
     * Suspend a Pterodactyl server.
     *
     * @param array $data - An associative array
     *                    - int 'order_id' (required) The order ID to suspend.
     */
    public function suspend($data): bool
    {
        if (empty($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required for suspension.');
        }

        $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        
        return $this->getService()->suspend($order, $model);
    }

    /**
     * Unsuspend a Pterodactyl server.
     *
     * @param array $data - An associative array
     *                    - int 'order_id' (required) The order ID to unsuspend.
     */
    public function unsuspend($data): bool
    {
        if (empty($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required for unsuspension.');
        }

        $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        
        return $this->getService()->unsuspend($order, $model);
    }

    /**
     * Test connection to Pterodactyl.
     *
     * @return array
     */
    public function test_connection(): array
    {
        return $this->getService()->testConnection();
    }
}
