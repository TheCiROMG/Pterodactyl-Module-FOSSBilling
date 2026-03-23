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

class Guest extends \Api_Abstract
{
    /**
     * Get available locations
     *
     * @return array
     */
    public function get_locations($data)
    {
        $service = $this->getService();
        $locations = $service->getLocations();
        
        // If product ID provided, check availability
        if (!empty($data['product_id'])) {
            try {
                $product = $this->di['db']->load('product', $data['product_id']);
                if ($product && !empty($product->config)) {
                    $config = json_decode($product->config, true);
                    
                    // Always calculate availability for potential plans
                    $nodes = $service->getNodes();
                    $nodesByLocation = [];
                    foreach ($nodes as $node) {
                        $nodesByLocation[$node['location_id']][] = $node;
                    }
                    
                    foreach ($locations as &$loc) {
                        $loc['nodes_capacity'] = [];
                        $loc['is_full'] = true; // Default to full until proven otherwise
                        
                        // Check against default product config if set
                        $reqMemory = (int)($config['memory'] ?? 0);
                        $reqDisk = (int)($config['disk'] ?? 0);
                        
                        if (isset($nodesByLocation[$loc['id']])) {
                            foreach ($nodesByLocation[$loc['id']] as $node) {
                                if (!empty($node['maintenance_mode'])) continue;
                                
                                // Calculate Free Memory
                                $memOver = $node['memory_overallocate'] ?? 0;
                                $memTotal = ($memOver < 0) ? PHP_INT_MAX : $node['memory'] * (1 + ($memOver / 100));
                                $memUsed = $node['allocated_resources']['memory'] ?? 0;
                                $memFree = $memTotal - $memUsed;
                                
                                // Calculate Free Disk
                                $diskOver = $node['disk_overallocate'] ?? 0;
                                $diskTotal = ($diskOver < 0) ? PHP_INT_MAX : $node['disk'] * (1 + ($diskOver / 100));
                                $diskUsed = $node['allocated_resources']['disk'] ?? 0;
                                $diskFree = $diskTotal - $diskUsed;
                                
                                // Store capacity for JS checks
                                $loc['nodes_capacity'][] = [
                                    'memory' => $memFree,
                                    'disk' => $diskFree
                                ];
                                
                                // Check default config
                                if ($memFree >= $reqMemory && $diskFree >= $reqDisk) {
                                    $loc['is_full'] = false;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors
            }
        }
        
        return $locations;
    }

    /**
     * Get variables for a specific Egg
     *
     * @param array $data
     * @return array
     */
    public function get_egg_variables($data)
    {
        if (empty($data['egg_id'])) {
            return [];
        }
        
        try {
            $eggInfo = $this->getService()->getEggInfo((int)$data['egg_id']);
            return $eggInfo['relationships']['variables']['data'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
