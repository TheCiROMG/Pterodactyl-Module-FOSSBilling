<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicepterodactyl;

use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    private ?array $panelConfig = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function attachOrderConfig(\Model_Product $product, array $data): array
    {
        !empty($product->config) ? $config = json_decode($product->config, true) : $config = [];

        // Validate required public variables
        // We do NOT throw exceptions here to allow "Plug and Play" ordering even if the theme doesn't support custom fields.
        // The user can configure variables in the Client Area after purchase.
        
        // Merge product config with submitted data (submitted data overrides product defaults)
        return array_merge($config, $data);
    }
    
    /**
     * Hook called after order is created.
     * Use this to ensure the service record exists if the create method wasn't called or failed silently.
     */
    public static function onAfterClientOrderCreate(\Box_Event $event)
    {
        self::_createServiceRecord($event);
    }

    /**
     * Hook called after admin creates an order.
     */
    public static function onAfterAdminOrderCreate(\Box_Event $event)
    {
        self::_createServiceRecord($event);
    }

    /**
     * Hook called after admin updates an order.
     * Use this to sync service status with order status if changed manually.
     */
    public static function onAfterAdminOrderUpdate(\Box_Event $event)
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        $orderId = $params['id'];
        
        try {
            $order = $di['db']->load('client_order', $orderId);
            if (!$order) {
                return;
            }
            
            // Only process if this is a Pterodactyl service order
            $product = $di['db']->load('product', $order->product_id);
            if (!$product || $product->type !== 'pterodactyl') {
                return;
            }
            
            if (!$order->service_id) {
                return;
            }

            $service = $di['db']->findOne('service_pterodactyl', 'id = ?', [$order->service_id]);
            if (!$service) {
                return;
            }

            // Sync status from order to service if they differ
            // We do NOT trigger provision/suspend/cancel logic here to avoid double-actions or side effects
            // This is purely to ensure the DB status matches the Order status for display/consistency
            if ($service->status !== $order->status) {
                $service->status = $order->status;
                $service->updated_at = date('Y-m-d H:i:s');
                $di['db']->store($service);
            }
            
        } catch (\Exception $e) {
            error_log('Error in onAfterAdminOrderUpdate for Pterodactyl: ' . $e->getMessage());
        }
    }

    private static function _createServiceRecord(\Box_Event $event)
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        $orderId = $params['id'];
        
        try {
            $order = $di['db']->load('client_order', $orderId);
            if (!$order) {
                return;
            }
            
            // Only process if this is a Pterodactyl service order
            $product = $di['db']->load('product', $order->product_id);
            if (!$product || $product->type !== 'pterodactyl') {
                return;
            }
            
            // Check if service record already exists
            // Note: service_id might be null or 0 if not yet assigned
            if ($order->service_id) {
                $service = $di['db']->findOne('service_pterodactyl', 'id = ?', [$order->service_id]);
                if ($service) {
                    return;
                }
            }
            
            // If not exists, create it manually
            $service = $di['mod_service']('servicepterodactyl');
            // Ensure create receives the order object
            $model = $service->create($order);
            
            // Update order with new service ID if needed
            if ($model && !$order->service_id) {
                $order->service_id = $model->id;
                $di['db']->store($order);
            }
            
        } catch (\Exception $e) {
            error_log('Error in _createServiceRecord for Pterodactyl: ' . $e->getMessage());
        }
    }

    public function create($order)
    {
        // Debug log
        $logFile = __DIR__ . '/service_log.txt';
        $logMsg = date('Y-m-d H:i:s') . " - Create called for Order ID: " . ($order->id ?? 'unknown') . "\n";
        
        try {
            // Ensure config is not empty
            $orderConfig = json_decode($order->config, true);
            if (!is_array($orderConfig)) {
                $orderConfig = [];
            }
            
            $logMsg .= "Initial Order Config: " . json_encode($orderConfig) . "\n";

            // If product_id exists, merge with product config
            if (isset($order->product_id)) {
                $product = $this->di['db']->load('product', $order->product_id);
                if ($product && !empty($product->config)) {
                    $productConfig = json_decode($product->config, true);
                    if (is_array($productConfig)) {
                        // Product config defaults, overwritten by order config
                        $orderConfig = array_merge($productConfig, $orderConfig);
                        $logMsg .= "Merged Product Config. New keys: " . implode(',', array_keys($orderConfig)) . "\n";
                    }
                }
            }

            $model = $this->di['db']->dispense('service_pterodactyl');
            $model->client_id = $order->client_id;
            $model->config = json_encode($orderConfig);
            $model->created_at = date('Y-m-d H:i:s');
            $model->updated_at = date('Y-m-d H:i:s');
            $model->server_id = null;
            $model->server_identifier = null;
            $model->status = 'pending';

            $id = $this->di['db']->store($model);
            $logMsg .= "Stored model. ID: " . $id . "\n";

            file_put_contents($logFile, $logMsg, FILE_APPEND);
            return $model;

        } catch (\Exception $e) {
            $errorMsg = "ERROR in create: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents($logFile, $logMsg . $errorMsg, FILE_APPEND);
            error_log($errorMsg);
            throw new \FOSSBilling\Exception('Failed to create service record: ' . $e->getMessage());
        }
    }
    
    /**
     * Validate order config before creation
     */
    public function validate_config(array $config): bool
    {
        return true;
    }

    public function activate($order, $model): bool
    {
        return $this->provision($order, $model);
    }

    public function provision($order, $model): bool
    {
        $config = json_decode($order->config, 1);
        if (!is_object($model)) {
            throw new \FOSSBilling\Exception('Order does not exist.');
        }

        try {
            // Store panel config for later use
            $this->panelConfig = $this->getPanelConfig($config);
            
            $client = $this->di['db']->load('client', $model->client_id);
            if (!$client) {
                throw new \FOSSBilling\Exception('Client not found');
            }
            $serverData = $this->createPterodactylServer($config, $client, $model, $order);
            
            $model->server_id = $serverData['id'];
            $model->server_identifier = $serverData['identifier'];
            $model->status = 'active';
            $model->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($model);

            return true;
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to provision server: ' . $e->getMessage());
        }
    }

    public function suspend($order, $model): bool
    {
        try {
            $config = json_decode($order->config, 1);
            $this->panelConfig = $this->getPanelConfig($config);
            
            if ($model->server_id) {
                $this->suspendPterodactylServer($model->server_id);
            }
            
            $model->status = 'suspended';
            $model->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($model);

            return true;
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to suspend server: ' . $e->getMessage());
        }
    }

    public function unsuspend($order, $model): bool
    {
        try {
            $config = json_decode($order->config, 1);
            $this->panelConfig = $this->getPanelConfig($config);
            
            if ($model->server_id) {
                $this->unsuspendPterodactylServer($model->server_id);
            }
            
            $model->status = 'active';
            $model->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($model);

            return true;
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to unsuspend server: ' . $e->getMessage());
        }
    }

    public function cancel($order, $model): bool
    {
        // As per requirements, cancellation (after grace period) should terminate the server
        // to free up resources in Pterodactyl.
        $this->unprovision($order, $model);
        return true;
    }

    public function uncancel($order, $model): bool
    {
        // If the server was deleted (unprovisioned) during cancellation, re-provision it.
        if (empty($model->server_id)) {
            return $this->provision($order, $model);
        }
        
        // If server ID still exists, just unsuspend it
        return $this->unsuspend($order, $model);
    }

    public function renew($order, $model): bool
    {
        return true;
    }

    public function delete($order, $model): void
    {
        $this->unprovision($order, $model);
    }

    public function unprovision($order, $model): void
    {
        if (is_object($model)) {
            try {
                // Get panel config
                $config = [];
                if ($order) {
                    $config = json_decode($order->config, 1) ?? [];
                }
                $this->panelConfig = $this->getPanelConfig($config);
                
                // Delete server from Pterodactyl if exists
                if ($model->server_id) {
                    $this->deletePterodactylServer($model->server_id);
                }
                
                // Update model status instead of deleting
                $model->status = 'deleted';
                $model->server_id = null;
                $model->server_identifier = null;
                $model->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($model);
            } catch (\Exception $e) {
                throw new \FOSSBilling\Exception('Failed to unprovision server: ' . $e->getMessage());
            }
        }
    }

    public function toApiArray($model): array
    {
        $config = json_decode($model->config, true);
        
        // Remove sensitive information from config before sending to client
        unset($config['api_key']);
        unset($config['sso_secret']);
        unset($config['password']); // Initial password might be here
        
        $result = [
            'id' => $model->id,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
            'status' => $model->status ?? 'active',
            'server_id' => $model->server_id,
            'server_identifier' => $model->server_identifier,
            'config' => $config,
        ];
        
        // Add panel URL if server exists
        if ($model->server_identifier) {
            try {
                $panelConfig = $this->getGlobalPanelConfig();
                $result['panel_url'] = rtrim($panelConfig['panel_url'], '/') . '/server/' . $model->server_identifier;
            } catch (\Exception $e) {
                $result['panel_url'] = null;
            }
        }
        
        return $result;
    }


    /**
     * Creates the database structure to store the Pterodactyl server information.
     */
    public function install(): bool
    {
        $sql = '
        CREATE TABLE IF NOT EXISTS `service_pterodactyl` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT UNIQUE,
            `client_id` bigint(20) NOT NULL,
            `server_id` bigint(20),
            `server_identifier` varchar(36),
            `status` varchar(50) DEFAULT "pending",
            `config` text NOT NULL,
            `created_at` datetime,
            `updated_at` datetime,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;';
        $this->di['db']->exec($sql);

        return true;
    }

    /**
     * Removes the Pterodactyl service table from the database.
     */
    public function uninstall(): bool
    {
        // We do NOT drop the table on uninstall to prevent data loss if the module is accidentally uninstalled.
        // $this->di['db']->exec('DROP TABLE IF EXISTS `service_pterodactyl`');

        return true;
    }

    /**
     * Test connection to Pterodactyl panel
     */
    public function testConnection(): array
    {
        try {
            // Check PHP requirements
            $phpVersion = phpversion();
            $curlEnabled = function_exists('curl_version');
            
            // Try to fetch system info (nodes) to verify API key
            $start = microtime(true);
            $api = $this->getApi();
            $response = $api->getNodes();
            $duration = round((microtime(true) - $start) * 1000, 2);
            
            $nodes = [];
            if (!empty($response['data'])) {
                foreach ($response['data'] as $node) {
                    $nodes[] = [
                        'name' => $node['attributes']['name'],
                        'fqdn' => $node['attributes']['fqdn'],
                        'scheme' => $node['attributes']['scheme'],
                        'port' => $node['attributes']['daemon_listen'],
                        'maintenance' => $node['attributes']['maintenance_mode'],
                    ];
                }
            }
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'latency' => $duration . 'ms',
                'php_version' => $phpVersion,
                'curl_enabled' => $curlEnabled,
                'node_count' => count($nodes),
                'nodes' => $nodes,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'php_version' => phpversion(),
                'curl_enabled' => function_exists('curl_version'),
            ];
        }
    }

    /**
     * Get all nodes from Pterodactyl
     */
    public function getNodes(): array
    {
        try {
            $api = $this->getApi();
            $response = $api->getNodes();
            $nodes = [];
            
            if (!empty($response['data'])) {
                foreach ($response['data'] as $node) {
                    $nodes[] = [
                        'id' => $node['attributes']['id'],
                        'name' => $node['attributes']['name'],
                        'location_id' => $node['attributes']['location_id'],
                        'public' => $node['attributes']['public'],
                        'maintenance' => $node['attributes']['maintenance_mode'],
                    ];
                }
            }
            return $nodes;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get all locations from Pterodactyl
     */
    public function getLocations(): array
    {
        try {
            $api = $this->getApi();
            $response = $api->getLocations();
            $locations = [];
            
            if (!empty($response['data'])) {
                foreach ($response['data'] as $location) {
                    $locations[] = [
                        'id' => $location['attributes']['id'],
                        'short' => $location['attributes']['short'],
                        'long' => $location['attributes']['long'],
                    ];
                }
            }
            return $locations;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get all eggs from Pterodactyl
     */
    public function getEggs(): array
    {
        try {
            $api = $this->getApi();
            $response = $api->getEggs();
            $eggs = [];
            
            if (!empty($response['data'])) {
                foreach ($response['data'] as $nest) {
                    if (!empty($nest['attributes']['relationships']['eggs']['data'])) {
                        foreach ($nest['attributes']['relationships']['eggs']['data'] as $egg) {
                            $eggs[] = [
                                'id' => $egg['attributes']['id'],
                                'name' => $egg['attributes']['name'],
                                'nest_name' => $nest['attributes']['name'],
                                'docker_image' => $egg['attributes']['docker_image'],
                                'startup' => $egg['attributes']['startup'],
                            ];
                        }
                    }
                }
            }
            return $eggs;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get SSO URL for the client
     * 
     * @param OODBBean|Model_ClientOrder $order
     */
    public function getSSOUrl($order): string
    {
        // Unwrap model if needed, though properties are accessible on Model too
        // But to be safe with OODBBean type hints elsewhere if any
        
        $model = $this->di['db']->findOne('service_pterodactyl', 'id = ?', [$order->service_id]);
        if (!$model || !$model->server_id) {
            return '';
        }

        $config = json_decode($order->config, true);
        $panelConfig = $this->getPanelConfig($config);
        $ssoSecret = $panelConfig['sso_secret'] ?? '';

        if (empty($ssoSecret)) {
            return '';
        }

        try {
            $api = $this->getApi($config);
            $server = $api->getServerDetails($model->server_id);
            $userId = $server['attributes']['user'];
            
            // Use WemX SSO plugin method
            return $api->getSSORedirect($userId, $ssoSecret);
        } catch (\Exception $e) {
            if (isset($this->di['logger'])) {
                $this->di['logger']->error('Pterodactyl SSO Error: ' . $e->getMessage());
            }
            return '';
        }
    }

    /**
     * Create Pterodactyl server using allocation or deployment
     */
    private function createPterodactylServer(array $config, $client, $model, $order): array
    {
        $api = $this->getApi($config);
        $panelConfig = $this->panelConfig ?? $this->getPanelConfig($config);
        
        // First, we need to create or get the user
        $userEmail = $client->email ?? 'noemail@example.com';
        $userId = $this->getOrCreateUser($userEmail, $client);
        
        // Get egg information and prepare environment variables
        $eggId = (int)($config['egg_id'] ?? 0);
        if ($eggId === 0) {
            throw new \FOSSBilling\Exception('Egg ID not configured for this product');
        }

        $eggInfo = $this->getEggInfo($eggId);
        $environment = $this->prepareEnvironmentVariables($eggInfo, $config);
        
        // Determine Server Name
        $serverName = $config['server_name'] ?? 'Server-' . time();
        
        // Use pattern if available, or default to "Product Title - Client First Name Client Last Name"
        $pattern = $config['server_name_pattern'] ?? '{{ product.title }} - {{ client.first_name }} {{ client.last_name }}';
        if (!empty($pattern)) {
            $serverName = $this->parseVariables($pattern, $client, $model->id, $order);
        }

        $serverData = [
            'name' => $serverName,
            'user' => $userId,
            'egg' => $eggId,
            'docker_image' => !empty($config['docker_image']) ? $config['docker_image'] : $eggInfo['docker_image'],
            'startup' => !empty($config['startup_command']) ? $config['startup_command'] : $eggInfo['startup'],
            'environment' => $environment,
            'limits' => [
                'memory' => (int)($config['memory'] ?? 512),
                'swap' => (int)($config['swap'] ?? 0),
                'disk' => (int)($config['disk'] ?? 1024),
                'io' => (int)($config['io'] ?? 500),
                'cpu' => (int)($config['cpu'] ?? 100),
            ],
            'feature_limits' => [
                'databases' => (int)($config['databases'] ?? 0),
                'allocations' => (int)($config['allocations'] ?? 0),
                'backups' => (int)($config['backups'] ?? 0),
            ],
        ];

        if (!empty($config['server_description'])) {
            $serverData['description'] = $config['server_description'];
        }

        // Add CPU Pinning and OOM Killer toggle
        if (!empty($config['cpu_pinning'])) {
            $serverData['limits']['threads'] = $config['cpu_pinning'];
        }
        
        if (isset($config['oom_disabled'])) {
            $serverData['oom_disabled'] = (bool)$config['oom_disabled'];
        }

        // Determine Node ID with Fallback and Stock Checks
        $nodeId = null;
        $requiredMemory = (int)($config['memory'] ?? 0);
        $requiredDisk = (int)($config['disk'] ?? 0);

        // 1. Check for specific node ID in config (Admin set or Client Choice)
        if (!empty($config['node_id'])) {
            $nodeId = (int)$config['node_id'];
        } 
        // 2. Handle Client Selection Mode specific override (if passed differently)
        elseif (isset($config['node_selection_mode']) && $config['node_selection_mode'] === 'client' && !empty($config['selected_node_id'])) {
            $nodeId = (int)$config['selected_node_id'];
        }

        // 3. Location Auto-Selection (if no specific node set)
        if (!$nodeId && !empty($config['location_id'])) {
            $nodesInLocation = $this->getNodesInLocation((int)$config['location_id']);
            $allowedNodes = $panelConfig['allowed_nodes'] ?? [];
            
            foreach ($nodesInLocation as $node) {
                // Check if node is allowed globally
                if (!empty($allowedNodes) && !in_array($node['id'], $allowedNodes)) {
                    continue;
                }
                
                // Check resources
                try {
                    $this->checkNodeResources($node['id'], $requiredMemory, $requiredDisk);
                    $nodeId = $node['id'];
                    break; // Found a suitable node
                } catch (\Exception $e) {
                    continue; // Try next node
                }
            }
            
            if (!$nodeId) {
                throw new \FOSSBilling\Exception('No suitable node found in the selected location with sufficient resources.');
            }
        }

        // 4. Fallback to Global Default Node
        if (!$nodeId) {
            $nodeId = (int)($panelConfig['default_node'] ?? 0);
        }

        if (!$nodeId) {
            throw new \FOSSBilling\Exception('No node selected for deployment. Please configure a node or location.');
        }

        // Final Resource Check for the selected node (Validation)
        $this->checkNodeResources($nodeId, $requiredMemory, $requiredDisk);

        // Process environment variables and handle Auto Port
        $autoPortEnabled = false;

        // Check if any variable requests AUTO_PORT
        foreach ($environment as $key => $value) {
            if ($value === 'AUTO_PORT') {
                $autoPortEnabled = true;
                break;
            }
        }

        // Handle Port Allocation
        if ($autoPortEnabled || !empty($config['auto_port'])) {
            $allocation = $api->findFreeAllocation($nodeId);
            $allocationId = $allocation['id'];
            $port = $allocation['port'];
            
            // Replace AUTO_PORT in environment variables with the actual port
            foreach ($environment as $key => $value) {
                if ($value === 'AUTO_PORT') {
                    $environment[$key] = (string)$port;
                }
            }
        } else {
            $allocation = $api->findFreeAllocation($nodeId);
            $allocationId = $allocation['id'];
        }
        
        $serverData['allocation'] = [
            'default' => $allocationId,
        ];
        $serverData['environment'] = $environment;

        // Add feature limits (if supported by Pterodactyl API structure)
        // Usually, these are part of the main payload, not environment
        // Already handled above in $serverData construction
        
        $response = $api->createServer($serverData);
        
        if (!isset($response['attributes']['id'])) {
            throw new \FOSSBilling\Exception('Failed to create server on Pterodactyl');
        }

        // Store both the admin ID (for API calls) and identifier (for display)
        $serverId = $response['attributes']['id'];
        $serverIdentifier = $response['attributes']['identifier'];
        
        return ['id' => $serverId, 'identifier' => $serverIdentifier];
    }

    /**
     * Check if node has enough resources
     */
    private function checkNodeResources(int $nodeId, int $requiredMemory, int $requiredDisk): void
    {
        try {
            $api = $this->getApi();
            $response = $api->getNode($nodeId);
            if (empty($response['attributes'])) {
                return; 
            }
            $node = $response['attributes'];

            // Calculate Memory
            $totalMemory = $node['memory'];
            $usedMemory = $node['allocated_resources']['memory'];
            $memoryOverallocate = $node['memory_overallocate']; 
            
            $totalMemoryAvailable = ($memoryOverallocate == -1) ? PHP_INT_MAX : $totalMemory * (1 + ($memoryOverallocate / 100));
            $freeMemory = $totalMemoryAvailable - $usedMemory;

            // Calculate Disk
            $totalDisk = $node['disk'];
            $usedDisk = $node['allocated_resources']['disk'];
            $diskOverallocate = $node['disk_overallocate'];
            
            $totalDiskAvailable = ($diskOverallocate == -1) ? PHP_INT_MAX : $totalDisk * (1 + ($diskOverallocate / 100));
            $freeDisk = $totalDiskAvailable - $usedDisk;

            if ($freeMemory < $requiredMemory) {
                throw new \FOSSBilling\Exception("Selected node does not have enough memory available (Required: {$requiredMemory}MB, Available: {$freeMemory}MB).");
            }

            if ($freeDisk < $requiredDisk) {
                throw new \FOSSBilling\Exception("Selected node does not have enough disk space available (Required: {$requiredDisk}MB, Available: {$freeDisk}MB).");
            }

        } catch (\Exception $e) {
            if ($e instanceof \FOSSBilling\Exception) {
                throw $e;
            }
        }
    }


    /**
     * Suspend a server on Pterodactyl panel
     */
    private function suspendPterodactylServer(int $serverId): void
    {
        $this->getApi()->suspendServer($serverId);
    }

    /**
     * Unsuspend a server on Pterodactyl panel
     */
    private function unsuspendPterodactylServer(int $serverId): void
    {
        $this->getApi()->unsuspendServer($serverId);
    }

    /**
     * Delete a server on Pterodactyl panel
     */
    private function deletePterodactylServer(int $serverId): void
    {
        $this->getApi()->deleteServer($serverId);
    }

    /**
     * Get or create a user on Pterodactyl panel
     */
    private function getOrCreateUser(string $email, $client): int
    {
        try {
            $api = $this->getApi();
            // First try to find existing user
            $response = $api->getUsers($email);
            
            if (!empty($response['data'])) {
                return $response['data'][0]['attributes']['id'];
            }
            
            // Create new user if not found
            $userData = [
                'email' => $email,
                'username' => $this->generateUsername($email),
                'first_name' => $client->first_name ?? 'Client',
                'last_name' => $client->last_name ?? 'User',
                'password' => $this->generateRandomPassword(),
            ];
            
            $response = $api->createUser($userData);
            
            if (!isset($response['attributes']['id'])) {
                throw new \FOSSBilling\Exception('Failed to create user on Pterodactyl');
            }
            
            return $response['attributes']['id'];
            
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to get or create user: ' . $e->getMessage());
        }
    }

    /**
     * Generate username from email
     */
    private function generateUsername(string $email): string
    {
        $username = explode('@', $email)[0];
        $username = preg_replace('/[^a-zA-Z0-9]/', '', $username);
        $username = substr($username, 0, 20);
        
        if (empty($username)) {
            $username = 'user' . time();
        }
        
        return strtolower($username);
    }



    /**
     * Get nodes in a specific location
     */
    private function getNodesInLocation(int $locationId): array
    {
        try {
            // Fetch all nodes
            $api = $this->getApi();
            $response = $api->getNodes();
            $nodes = [];
            
            if (!empty($response['data'])) {
                foreach ($response['data'] as $node) {
                    if (isset($node['attributes']['location_id']) && $node['attributes']['location_id'] == $locationId) {
                        $nodes[] = $node['attributes'];
                    }
                }
            }
            
            return $nodes;
        } catch (\Exception $e) {
            return [];
        }
    }


    /**
     * Get egg information from Pterodactyl
     */
    public function getEggInfo(int $eggId, ?int $nestId = null): array
    {
        try {
            $api = $this->getApi();
            
            // If nestId is not provided, we need to find it
            if (!$nestId) {
                // First get all nests
                $nestsResponse = $api->getEggs();
                
                // Find which nest contains our egg
                if (!empty($nestsResponse['data'])) {
                    foreach ($nestsResponse['data'] as $nest) {
                        if (isset($nest['attributes']['relationships']['eggs']['data'])) {
                            foreach ($nest['attributes']['relationships']['eggs']['data'] as $egg) {
                                if ($egg['attributes']['id'] === $eggId) {
                                    $nestId = $nest['attributes']['id'];
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
            
            if (!$nestId) {
                throw new \FOSSBilling\Exception('Could not find nest containing egg ID ' . $eggId);
            }
            
            // Get detailed egg info from the nest
            $response = $api->getEgg($nestId, $eggId);
            
            // Ensure we return attributes AND relationships
            $attributes = $response['attributes'] ?? [];
            if (isset($response['relationships'])) {
                 $attributes['relationships'] = $response['relationships'];
            }
            
            return $attributes;
            
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to get egg info: ' . $e->getMessage());
        }
    }

    /**
     * Prepare environment variables based on egg requirements
     */
    private function prepareEnvironmentVariables(array $eggInfo, array $config): array
    {
        $environment = [];
        
        // Start with user-provided environment variables (from product config)
        if (!empty($config['variables']) && is_array($config['variables'])) {
            $environment = $config['variables'];
        }
        
        // Add required egg variables with default values if not present
        if (!empty($eggInfo['relationships']['variables']['data'])) {
            foreach ($eggInfo['relationships']['variables']['data'] as $variable) {
                $varData = $variable['attributes'];
                $envKey = $varData['env_variable'];
                
                // If variable is not set by user, use default value
                if (!isset($environment[$envKey])) {
                    // Check root config for custom fields (case insensitive)
                    if (isset($config[$envKey])) {
                        $environment[$envKey] = $config[$envKey];
                    } elseif (isset($config[strtolower($envKey)])) {
                        $environment[$envKey] = $config[strtolower($envKey)];
                    }
                }

                if (!isset($environment[$envKey])) {
                    $defaultValue = $varData['default_value'] ?? '';
                    
                    // Set common default values for typical Minecraft variables
                    switch ($envKey) {
                        case 'SERVER_JARFILE':
                            $environment[$envKey] = $defaultValue ?: 'server.jar';
                            break;
                        case 'VANILLA_VERSION':
                        case 'MC_VERSION':
                        case 'VERSION':
                            $environment[$envKey] = $defaultValue ?: 'latest';
                            break;
                        case 'FORGE_VERSION':
                            $environment[$envKey] = $defaultValue ?: 'recommended';
                            break;
                        case 'BUILD_NUMBER':
                            $environment[$envKey] = $defaultValue ?: 'latest';
                            break;
                        default:
                            $environment[$envKey] = $defaultValue;
                            break;
                    }
                }
            }
        }
        
        // Process special variables (AUTO_PASSWORD, RANDOM_STRING, etc.)
        foreach ($environment as $key => $value) {
            if ($value === 'AUTO_PASSWORD' || $value === 'RANDOM_STRING' || $value === 'GENERATE_RANDOM') {
                $environment[$key] = $this->generateRandomPassword();
            }
        }
        
        return $environment;
    }

    /**
     * Get server startup variables from Pterodactyl.
     */
    public function getServerVariables(int $serverId): array
    {
        try {
            $response = $this->getApi()->getServerStartup($serverId);
            return $response['data'] ? array_map(function($item) {
                return $item['attributes'];
            }, $response['data']) : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Update server startup variables on Pterodactyl.
     */
    public function updateServerVariables(int $serverId, array $variables): void
    {
        $api = $this->getApi();
        
        // Fetch current config
        $response = $api->getServerDetails($serverId);
        $serverAttr = $response['attributes'];
        
        // Fetch current variables to merge
        $currentVars = $this->getServerVariables($serverId);
        $mergedVars = [];
        foreach ($currentVars as $v) {
            $mergedVars[$v['env_variable']] = $v['server_value'];
        }
        foreach ($variables as $k => $v) {
            $mergedVars[$k] = $v;
        }
        
        $payload = [
            'startup' => $serverAttr['container']['startup_command'],
            'environment' => $mergedVars,
            'egg' => $serverAttr['egg'],
            'image' => $serverAttr['container']['image'],
            'skip_scripts' => true
        ];

        $api->updateServerStartup($serverId, $payload);
    }

    /**
     * Update server configuration (limits)
     */
    public function updateServer(array $data): bool
    {
        if (empty($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required to update server');
        }

        $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);

        if (!$model->server_id) {
            throw new \FOSSBilling\Exception('Server not provisioned');
        }

        $config = json_decode($model->config, true) ?? [];
        
        // Update config with provided data
        if (isset($data['config']) && is_array($data['config'])) {
            $config = array_merge($config, $data['config']);
            $model->config = json_encode($config);
            $model->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($model);
        }

        try {
            // Get panel config
            $this->panelConfig = $this->getPanelConfig($config);

            // Get current server info to get allocation ID
            $serverInfo = $this->getServerInfo($model->server_id);
            if (!isset($serverInfo['allocation'])) {
                throw new \FOSSBilling\Exception('Could not retrieve server allocation ID');
            }

            // Prepare build data
            $buildData = [
                'allocation' => $serverInfo['allocation'],
                'memory' => (int)($config['memory'] ?? 512),
                'swap' => (int)($config['swap'] ?? 0),
                'disk' => (int)($config['disk'] ?? 1024),
                'io' => (int)($config['io'] ?? 500),
                'cpu' => (int)($config['cpu'] ?? 100),
                'feature_limits' => [
                    'databases' => (int)($config['databases'] ?? 0),
                    'allocations' => (int)($config['allocations'] ?? 0),
                    'backups' => (int)($config['backups'] ?? 0),
                ],
            ];

            $this->getApi()->updateServerBuild($model->server_id, $buildData);
            
            return true;
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to update server build: ' . $e->getMessage());
        }
    }


    /**
     * Parse variables like {{ client.first_name }}
     */
    private function parseVariables(string $text, $client, int $serviceId, $order = null): string
    {
        $vars = [
            '{{ client.id }}' => $client->id,
            '{{ client.first_name }}' => $client->first_name,
            '{{ client.last_name }}' => $client->last_name,
            '{{ service.id }}' => $serviceId,
            '{{ date }}' => date('Y-m-d'),
        ];
        
        if ($order && isset($order->title)) {
            $vars['{{ product.title }}'] = $order->title;
        }

        return str_replace(array_keys($vars), array_values($vars), $text);
    }
    
    /**
     * Generate a random password/string
     */
    private function generateRandomPassword(int $length = 16): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Get server information from Pterodactyl
     */
    public function getServerInfo(int $serverId): array
    {
        try {
            $response = $this->getApi()->getServerDetails($serverId);
            return $response['attributes'] ?? [];
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to get server info: ' . $e->getMessage());
        }
    }

    /**
     * Get server status from Pterodactyl
     */
    public function getServerStatus(int $serverId): array
    {
        try {
            $response = $this->getApi()->getServerResources($serverId);
            return $response['attributes'] ?? [];
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to get server status: ' . $e->getMessage());
        }
    }

    /**
     * Get API client instance
     */
    private function getApi(array $config = []): PterodactylApi
    {
        $panelConfig = $this->getPanelConfig($config);
        
        if (empty($panelConfig['panel_url']) || empty($panelConfig['api_key'])) {
            throw new \FOSSBilling\Exception('Pterodactyl settings are not configured. Please check module settings.');
        }

        return new PterodactylApi(
            $panelConfig['panel_url'],
            $panelConfig['api_key'],
            isset($this->di['logger']) ? $this->di['logger'] : null
        );
    }

    /**
     * Get panel configuration from order/product
     */
    private function getPanelConfig(array $orderConfig = []): array
    {
        // First try to get config from order
        if (!empty($orderConfig['panel_url']) && !empty($orderConfig['api_key'])) {
            return $orderConfig;
        }
        
        // Try to get from global service configuration (stored in a settings table or config file)
        $globalConfig = $this->getGlobalPanelConfig();
        
        $config = [
            'panel_url' => $orderConfig['panel_url'] ?? $globalConfig['panel_url'] ?? '',
            'api_key' => $orderConfig['api_key'] ?? $globalConfig['api_key'] ?? '',
            'sso_secret' => $orderConfig['sso_secret'] ?? $globalConfig['sso_secret'] ?? '',
            'allowed_nodes' => $globalConfig['allowed_nodes'] ?? [],
            'default_node' => $globalConfig['default_node'] ?? 0,
        ];

        return $config;
    }

    /**
     * Get global panel configuration from system settings
     */
    private function getGlobalPanelConfig(): array
    {
        try {
            // Try to get from system settings or config table
            $settingService = $this->di['mod_service']('system');
            
            return [
                'panel_url' => $settingService->getParamValue('servicepterodactyl_panel_url', ''),
                'api_key' => $settingService->getParamValue('servicepterodactyl_api_key', ''),
                'sso_secret' => $settingService->getParamValue('servicepterodactyl_sso_secret', ''),
                'allowed_nodes' => json_decode($settingService->getParamValue('servicepterodactyl_allowed_nodes', '[]'), true) ?? [],
                'default_node' => $settingService->getParamValue('servicepterodactyl_default_node', 0),
            ];
        } catch (\Exception $e) {
            // If system service not available, return empty array
            return [];
        }
    }
}

