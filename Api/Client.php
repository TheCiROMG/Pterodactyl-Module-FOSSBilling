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
            throw new \FOSSBilling\Exception('SSO not available. Please check if the WemX SSO plugin is installed and configured correctly on the panel.');
        }
        
        return $url;
    }

    /**
     * Validate node resources before payment
     *
     * @param array $data
     * @return bool
     */
    public function validate_stock($data): bool
    {
        if (empty($data['product_id'])) {
            throw new \FOSSBilling\Exception('Product ID is required');
        }

        $product = $this->di['db']->getExistingModelById('Product', $data['product_id'], 'Product not found');
        
        // Product configuration
        $config = json_decode($product->config, true) ?? [];
        
        // Merge with any submitted data (like custom fields)
        $orderConfig = array_merge($config, $data);
        
        $service = $this->getService();
        
        try {
            $service->validateResources($orderConfig);
            return true;
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception("No se a podido aprovisionar/activar el servicio, el pago a sido cancelado, por favor, contacte a un administrador. (Detalle: " . $e->getMessage() . ")");
        }
    }
}
