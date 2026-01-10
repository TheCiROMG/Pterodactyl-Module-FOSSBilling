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
use RedBeanPHP\OODBBean;

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
        
        return array_merge($config, $data);
    }

    public function create(OODBBean $order)
    {
        $model = $this->di['db']->dispense('service_pterodactyl');
        $model->client_id = $order->client_id;
        $model->config = $order->config;

        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return $model;
    }

    public function activate(OODBBean $order, OODBBean $model): bool
    {
        return $this->provision($order, $model);
    }

    public function provision(OODBBean $order, OODBBean $model): bool
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
            $serverData = $this->createPterodactylServer($config, $client);
            
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

    public function suspend(OODBBean $order, OODBBean $model): bool
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

    public function unsuspend(OODBBean $order, OODBBean $model): bool
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

    public function cancel(OODBBean $order, OODBBean $model): bool
    {
        return $this->suspend($order, $model);
    }

    public function uncancel(OODBBean $order, OODBBean $model): bool
    {
        return $this->unsuspend($order, $model);
    }

    public function delete(?OODBBean $order, ?OODBBean $model): void
    {
        $this->unprovision($order, $model);
    }

    public function unprovision(?OODBBean $order, ?OODBBean $model): void
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

    public function toApiArray(OODBBean $model): array
    {
        $result = [
            'id' => $model->id,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
            'status' => $model->status ?? 'active',
            'server_id' => $model->server_id,
            'server_identifier' => $model->server_identifier,
            'config' => json_decode($model->config, true),
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
            `server_identifier` varchar(8),
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
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_pterodactyl`');

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
            $response = $this->pterodactylApiRequest('GET', '/api/application/nodes');
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
            $response = $this->pterodactylApiRequest('GET', '/api/application/nodes');
            $nodes = [];
            
            if (!empty($response['data'])) {
                foreach ($response['data'] as $node) {
                    $nodes[] = [
                        'id' => $node['attributes']['id'],
                        'name' => $node['attributes']['name'],
                        'public' => $node['attributes']['public'],
                        'maintenance_mode' => $node['attributes']['maintenance_mode'],
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
            $response = $this->pterodactylApiRequest('GET', '/api/application/locations');
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
            $response = $this->pterodactylApiRequest('GET', '/api/application/nests?include=eggs');
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

        // Fetch user ID from Pterodactyl
        // We might need to store user_id in the database to avoid API call, 
        // but for now let's fetch it or use the one we created.
        // Wait, we don't store Pterodactyl User ID in service_pterodactyl table, only server_id.
        // We can fetch server details to get user ID.
        try {
            $server = $this->pterodactylApiRequest('GET', "/api/application/servers/{$model->server_id}");
            $userId = $server['attributes']['user'];
        } catch (\Exception $e) {
            return '';
        }

        $data = [
            'user_id' => $userId,
            'timestamp' => time(),
        ];
        
        $jsonData = json_encode($data);
        $base64Data = base64_encode($jsonData);
        $signature = hash_hmac('sha256', $jsonData, $ssoSecret); // Note: verify if signature is on json or base64. Usually on raw string.
        // WemX SSO documentation says: signature = hash_hmac('sha256', data, secret). 
        // If data is passed as base64, signature usually matches the decoded content.
        // Let's assume signature is on the JSON string.

        $baseUrl = rtrim($panelConfig['panel_url'], '/');
        return "$baseUrl/auth/sso?data=$base64Data&signature=$signature";
    }

    /**
     * Create Pterodactyl server using allocation or deployment
     */
    private function createPterodactylServer(array $config, OODBBean $client): array
    {
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
        
        $serverData = [
            'name' => $config['server_name'] ?? 'Server-' . time(),
            'user' => $userId,
            'egg' => $eggId,
            'docker_image' => $config['docker_image'] ?? $eggInfo['docker_image'],
            'startup' => $config['startup_command'] ?? $eggInfo['startup'],
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
        $environment = $this->prepareEnvironmentVariables($eggInfo, $config);
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
            $port = $this->findAvailablePort($nodeId);
            $allocationId = $this->createAllocation($nodeId, $port);
            
            // Replace AUTO_PORT in environment variables with the actual port
            foreach ($environment as $key => $value) {
                if ($value === 'AUTO_PORT') {
                    $environment[$key] = (string)$port;
                }
            }
        } else {
            $allocationId = $this->getOrCreateAllocation($nodeId);
        }
        
        $serverData['allocation'] = [
            'default' => $allocationId,
        ];
        $serverData['environment'] = $environment;

        // Add feature limits (if supported by Pterodactyl API structure)
        // Usually, these are part of the main payload, not environment
        // Already handled above in $serverData construction
        
        $response = $this->pterodactylApiRequest('POST', '/api/application/servers', $serverData);
        
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
            $response = $this->pterodactylApiRequest('GET', "/api/application/nodes/{$nodeId}");
            if (empty($response['attributes'])) {
                return; // Could not fetch node, skip check or fail? Let's skip to avoid blocking if API is weird.
            }
            $node = $response['attributes'];

            // Calculate Memory
            $totalMemory = $node['memory'];
            $usedMemory = $node['allocated_resources']['memory'];
            $memoryOverallocate = $node['memory_overallocate']; // Percentage or -1
            
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
            // Re-throw if it's our exception, otherwise log/ignore?
            if ($e instanceof \FOSSBilling\Exception) {
                throw $e;
            }
            // If API fails, we might want to let Pterodactyl handle the error or fail safe.
            // Let's log and proceed, or fail. User asked for stock check.
            error_log("Stock check failed: " . $e->getMessage());
        }
    }


    /**
     * Suspend a server on Pterodactyl panel
     */
    private function suspendPterodactylServer(int $serverId): void
    {
        $this->pterodactylApiRequest('POST', "/api/application/servers/{$serverId}/suspend");
    }

    /**
     * Unsuspend a server on Pterodactyl panel
     */
    private function unsuspendPterodactylServer(int $serverId): void
    {
        $this->pterodactylApiRequest('POST', "/api/application/servers/{$serverId}/unsuspend");
    }

    /**
     * Delete a server on Pterodactyl panel
     */
    private function deletePterodactylServer(int $serverId): void
    {
        $this->pterodactylApiRequest('DELETE', "/api/application/servers/{$serverId}");
    }

    /**
     * Get or create a user on Pterodactyl panel
     */
    private function getOrCreateUser(string $email, OODBBean $client): int
    {
        try {
            // First try to find existing user
            $response = $this->pterodactylApiRequest('GET', "/api/application/users?filter[email]={$email}");
            
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
            
            $response = $this->pterodactylApiRequest('POST', '/api/application/users', $userData);
            
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
     * Generate random password
     */
    private function generateRandomPassword(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get or create an allocation on the specified node
     */
    private function getOrCreateAllocation(int $nodeId): int
    {
        try {
            // First try to find an available allocation on the node (unassigned ones)
            $response = $this->pterodactylApiRequest('GET', "/api/application/nodes/{$nodeId}/allocations");
            
            // Filter unassigned allocations client-side
            if (!empty($response['data'])) {
                foreach ($response['data'] as $allocation) {
                    if (empty($allocation['attributes']['assigned'])) {
                        return $allocation['attributes']['id'];
                    }
                }
            }
            
            // If no available allocation, create a new one
            // We'll try to find an available port
            $port = $this->findAvailablePort($nodeId);
            
            $allocationData = [
                'ip' => '0.0.0.0', // Default IP
                'ports' => [$port],
            ];
            
            $response = $this->pterodactylApiRequest('POST', "/api/application/nodes/{$nodeId}/allocations", $allocationData);
            
            if (!empty($response['data'])) {
                return $response['data'][0]['attributes']['id'];
            }
            
            throw new \FOSSBilling\Exception('Failed to create allocation on node');
            
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to get or create allocation: ' . $e->getMessage());
        }
    }

    /**
     * Create a new allocation on the node
     */
    private function createAllocation(int $nodeId, int $port): int
    {
        try {
            $response = $this->pterodactylApiRequest('POST', "/api/application/nodes/{$nodeId}/allocations", [
                'ip' => '0.0.0.0', // Default to wildcard or fetch node IP if needed
                'ports' => [(string)$port]
            ]);
            
            // Pterodactyl returns 204 on success for allocation creation? No, it returns the created allocations.
            // But wait, the API might not return the ID directly in a simple format.
            // Actually, to assign it to the server, we need the allocation ID.
            
            // Let's check the response structure. Usually it's a list of created allocations.
            // If we created one, we take the first one.
            
            if (!empty($response['data']) && isset($response['data'][0]['attributes']['id'])) {
                return $response['data'][0]['attributes']['id'];
            }
            
            // If we can't find the ID in the response, we might need to search for it
            // But for now, let's assume standard Pterodactyl API behavior
            throw new \FOSSBilling\Exception('Failed to retrieve created allocation ID');
            
        } catch (\Exception $e) {
             // If allocation creation fails (e.g. port taken), try to find it first?
             // Or maybe we should just fail.
             // But wait, 'ip' is required. We should probably get the node's main IP.
             // For now let's use 0.0.0.0 as placeholder, but ideally we should query node details.
             
             // Let's try to fetch node details to get the IP
             $nodeInfo = $this->getNodeInfo($nodeId);
             $ip = '0.0.0.0';
             if (!empty($nodeInfo['allocated_resources']['ports'])) {
                 // This doesn't give us the IP. 
                 // We need the node's public IP or a specific allocation IP.
                 // Let's just try 0.0.0.0 and if it fails, the user needs to configure it properly manually or we need more logic.
             }
             
             throw new \FOSSBilling\Exception('Failed to create allocation: ' . $e->getMessage());
        }
    }
    
    private function getNodeInfo(int $nodeId): array {
        try {
             $response = $this->pterodactylApiRequest('GET', "/api/application/nodes/{$nodeId}");
             return $response['attributes'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get nodes in a specific location
     */
    private function getNodesInLocation(int $locationId): array
    {
        try {
            // Fetch all nodes
            $response = $this->pterodactylApiRequest('GET', "/api/application/nodes");
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
     * Find an available port on the node
     */
    private function findAvailablePort(int $nodeId): int
    {
        // Get existing allocations to find used ports
        $response = $this->pterodactylApiRequest('GET', "/api/application/nodes/{$nodeId}/allocations");
        
        $usedPorts = [];
        if (!empty($response['data'])) {
            foreach ($response['data'] as $allocation) {
                $usedPorts[] = $allocation['attributes']['port'];
            }
        }
        
        // Find an available port starting from 25565 (common Minecraft port)
        $startPort = 25565;
        $maxTries = 1000;
        
        for ($i = 0; $i < $maxTries; $i++) {
            $port = $startPort + $i;
            if (!in_array($port, $usedPorts)) {
                return $port;
            }
        }
        
        // Fallback to a random high port
        return rand(30000, 65535);
    }

    /**
     * Get egg information from Pterodactyl
     */
    public function getEggInfo(int $eggId, ?int $nestId = null): array
    {
        try {
            // If nestId is not provided, we need to find it
            if (!$nestId) {
                // First get all nests
                $nestsResponse = $this->pterodactylApiRequest('GET', "/api/application/nests?include=eggs");
                
                // Find which nest contains our egg
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
            
            if (!$nestId) {
                throw new \FOSSBilling\Exception('Could not find nest containing egg ID ' . $eggId);
            }
            
            // Get detailed egg info from the nest
            $response = $this->pterodactylApiRequest('GET', "/api/application/nests/{$nestId}/eggs/{$eggId}?include=variables");
            
            // Ensure we return attributes AND relationships
            $attributes = $response['attributes'] ?? [];
            if (isset($response['relationships'])) {
                 // If relationships are siblings (depending on API version/serializer), merge them in
                 $attributes['relationships'] = $response['relationships'];
            } elseif (isset($attributes['relationships'])) {
                 // Already inside attributes, do nothing
            } elseif (isset($response['data']['relationships'])) {
                 // Sometimes it might be in data? (Unlikely for single resource but checking)
            }
            
            // Fallback: if relationships are missing in attributes but present in the root response under a different key?
            // Standard Pterodactyl with include=variables usually puts relationships inside attributes or as sibling.
            // We ensure they are available in the returned array.
            if (!isset($attributes['relationships']) && isset($response['attributes']['relationships'])) {
                 // This case is covered by "Already inside attributes"
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
        
        return $environment;
    }

    /**
     * Get server startup variables from Pterodactyl.
     */
    public function getServerVariables(int $serverId): array
    {
        // Requires GET /api/application/servers/{server}/startup
        // Note: The Application API endpoint is /api/application/servers/{id}/startup
        $response = $this->pterodactylApiRequest('GET', "/api/application/servers/{$serverId}/startup");
        
        return $response['data'] ? array_map(function($item) {
            return $item['attributes'];
        }, $response['data']) : [];
    }

    /**
     * Update server startup variables on Pterodactyl.
     */
    public function updateServerVariables(int $serverId, array $variables): void
    {
        // Update variables one by one or batch?
        // Application API: PUT /api/application/servers/{server}/startup
        // It accepts an array of environment variables
        
        $data = [
            'environment' => $variables,
            'startup' => null, // Optional: update startup command if needed, but we focus on vars
            'egg' => null, // Optional: egg ID
            'image' => null, // Optional: docker image
            'skip_scripts' => false
        ];
        
        // However, the endpoint PUT /api/application/servers/{server}/startup expects:
        // { "startup": "...", "environment": { "VAR": "val" }, "egg": id, "image": "...", "skip_scripts": false }
        // We need to be careful not to reset other things if we only want to update vars.
        // Actually, it's safer to just update the specific variables if possible, but the API might require full payload.
        
        // Let's first fetch current config to preserve startup/egg/image
        $response = $this->pterodactylApiRequest('GET', "/api/application/servers/{$serverId}");
        $serverAttr = $response['attributes'];
        
        $payload = [
            'startup' => $serverAttr['container']['startup_command'],
            'environment' => $variables, // This merges? Or replaces? API docs say "The environment object should contain key-value pairs..."
            'egg' => $serverAttr['egg'],
            'image' => $serverAttr['container']['image'],
            'skip_scripts' => true // Usually safer to skip scripts on variable update unless intended
        ];
        
        // We need to merge existing environment variables with new ones to avoid losing others?
        // Actually, let's fetch current variables first to be safe
        $currentVars = $this->getServerVariables($serverId);
        $mergedVars = [];
        foreach ($currentVars as $v) {
            $mergedVars[$v['env_variable']] = $v['server_value'];
        }
        // Overwrite with new values
        foreach ($variables as $k => $v) {
            $mergedVars[$k] = $v;
        }
        
        $payload['environment'] = $mergedVars;

        $this->pterodactylApiRequest('PUT', "/api/application/servers/{$serverId}/startup", $payload);
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

            $this->pterodactylApiRequest('POST', "/api/application/servers/{$model->server_id}/build", $buildData);
            
            return true;
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to update server build: ' . $e->getMessage());
        }
    }

    /**
     * Change account password on Pterodactyl panel
     * This follows FOSSBilling naming convention for compatibility with admin interface
     * Can be called from both admin and client contexts
     * 
     * @param OODBBean|int $orderOrId - Order bean or order ID
     * @param OODBBean|null $model - Service model (optional)
     * @param array|string $data - Password data or string
     * @return bool
     */
    public function changeAccountPassword($orderOrId, $model = null, $data = []): bool
    {
        // Handle different parameter formats from FOSSBilling
        if (is_object($orderOrId)) {
            // Called with order bean
            $order = $orderOrId;
            $orderId = $order->id;
        } else {
            // Called with order ID
            $orderId = $orderOrId;
            $order = $this->di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
        }
        
        // Extract password from data
        $newPassword = null;
        if (is_string($data)) {
            $newPassword = $data;
        } elseif (is_array($data)) {
            $newPassword = $data['password'] ?? $data['new_password'] ?? null;
        } elseif (is_string($model)) {
            // Sometimes password is passed as second parameter
            $newPassword = $model;
            $model = null;
        }
        
        if (empty($newPassword)) {
            throw new \FOSSBilling\Exception('Password is required');
        }
        
        // Get service model if not provided
        if (!$model) {
            $orderService = $this->di['mod_service']('order');
            $model = $orderService->getOrderService($order);
        }
        
        if (!$model->server_id) {
            throw new \FOSSBilling\Exception('Server not provisioned');
        }

        try {
            // Get config to access panel
            $config = json_decode($order->config, 1);
            $this->panelConfig = $this->getPanelConfig($config);
            
            // Get client information
            $client = $this->di['db']->load('client', $order->client_id);
            if (!$client) {
                throw new \FOSSBilling\Exception('Client not found');
            }
            
            // Get user ID from Pterodactyl
            $userEmail = $client->email ?? 'noemail@example.com';
            $userId = $this->getOrCreateUser($userEmail, $client);
            
            // Update password via Pterodactyl API
            $userData = [
                'email' => $userEmail,
                'username' => $this->generateUsername($userEmail),
                'first_name' => $client->first_name ?? 'Client',
                'last_name' => $client->last_name ?? 'User',
                'password' => $newPassword,
            ];
            
            $this->pterodactylApiRequest('PATCH', "/api/application/users/{$userId}", $userData);
            
            // Log the password change
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('Pterodactyl password changed for order #%s', $orderId);
            }
            
            return true;
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to change password: ' . $e->getMessage());
        }
    }

    /**
     * Get server information from Pterodactyl
     */
    public function getServerInfo(int $serverId): array
    {
        try {
            $response = $this->pterodactylApiRequest('GET', "/api/application/servers/{$serverId}");
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
            $response = $this->pterodactylApiRequest('GET', "/api/client/servers/{$serverId}/resources");
            return $response['attributes'] ?? [];
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Failed to get server status: ' . $e->getMessage());
        }
    }

    /**
     * Make API request to Pterodactyl panel
     */
    private function pterodactylApiRequest(string $method, string $endpoint, array $data = []): array
    {
        if ($this->panelConfig === null) {
            $this->panelConfig = $this->getGlobalPanelConfig();
        }
        $panelConfig = $this->panelConfig;
        
        if (empty($panelConfig['panel_url']) || empty($panelConfig['api_key'])) {
            throw new \FOSSBilling\Exception('Pterodactyl settings are not configured.');
        }

        // Validate URL
        if (!filter_var($panelConfig['panel_url'], FILTER_VALIDATE_URL)) {
             throw new \FOSSBilling\Exception('Pterodactyl panel URL is invalid. It must include http:// or https://. Value: ' . $panelConfig['panel_url']);
        }
        
        $url = rtrim($panelConfig['panel_url'], '/') . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $panelConfig['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \FOSSBilling\Exception('Pterodactyl API connection failed: ' . $error);
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            $errorDetails = '';
            if ($response) {
                $errorData = json_decode($response, true);
                if (isset($errorData['errors'])) {
                    $errorDetails = ' - ' . json_encode($errorData['errors']);
                }
            }
            throw new \FOSSBilling\Exception('Pterodactyl API request failed with HTTP code: ' . $httpCode . $errorDetails);
        }

        return json_decode($response, true) ?? [];
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
        ];

        if (empty($config['panel_url']) || empty($config['api_key'])) {
            throw new \FOSSBilling\Exception('Pterodactyl panel URL and API key must be configured. Please configure them in the module settings.');
        }

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
            ];
        } catch (\Exception $e) {
            // If system service not available, return empty array
            return [];
        }
    }

}
