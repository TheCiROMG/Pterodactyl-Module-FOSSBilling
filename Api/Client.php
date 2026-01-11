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

class Client extends \Api_Abstract
{
    /**
     * Get service details
     *
     * @param array $data - An associative array
     *                    - int 'order_id' The order ID.
     */
    public function get($data): array
    {
        if (empty($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required');
        }
        
        $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        
        // Verify ownership
        $client = $this->getIdentity();
        if ($order->client_id !== $client->id) {
            throw new \FOSSBilling\Exception('Order not found');
        }
        
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        
        if (!$model) {
            throw new \FOSSBilling\Exception('Service not found');
        }
        
        $service = $this->getService();
        $result = $service->toApiArray($model);
        
        // SSO URL is now retrieved via dedicated sso() method to avoid slow page loads
        // and ensure secure token generation on demand.
        $result['sso_url'] = ''; // Kept for backward compatibility check in template

        return $result;
    }

    /**
     * Get SSO URL for client
     * This method performs the handshake with Pterodactyl server
     *
     * @param array $data
     * @return string
     */
    public function sso($data): string
    {
        if (empty($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required');
        }
        
        $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        
        // Verify ownership
        $client = $this->getIdentity();
        if ($order->client_id !== $client->id) {
            throw new \FOSSBilling\Exception('Order not found');
        }
        
        $service = $this->getService();
        $url = $service->getSSOUrl($order);
        
        if (empty($url)) {
            throw new \FOSSBilling\Exception('SSO not available. Please check if the WemX SSO plugin is installed and configured correctly on the Pterodactyl panel.');
        }
        
        return $url;
    }

    /**
     * Get server info for client
     *
     * @param array $data
     * @return array
     */
}
