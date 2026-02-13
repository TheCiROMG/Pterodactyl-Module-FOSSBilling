<?php

namespace Box\Mod\Servicepterodactyl;

class PterodactylApi
{
    private string $panelUrl;
    private string $apiKey;
    private $logger;

    public function __construct(string $panelUrl, string $apiKey, $logger = null)
    {
        $this->panelUrl = rtrim($panelUrl, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger;
    }

    /**
     * Make an HTTP request to the Pterodactyl API
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->panelUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: Application/vnd.pterodactyl.v1+json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 second timeout
        
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

        $decodedResponse = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            $errorMessage = 'Pterodactyl API request failed with HTTP code: ' . $httpCode;
            
            if (isset($decodedResponse['errors']) && is_array($decodedResponse['errors'])) {
                $errorDetails = [];
                foreach ($decodedResponse['errors'] as $error) {
                    $errorDetails[] = $error['detail'] ?? $error['code'] ?? 'Unknown error';
                }
                $errorMessage .= ' - Details: ' . implode(', ', $errorDetails);
            }

            if ($this->logger) {
                try {
                    if (method_exists($this->logger, 'error')) {
                        $this->logger->error($errorMessage);
                    }
                } catch (\Throwable $logErr) {
                    // Ignore logger failures to avoid breaking provisioning
                }
            }
            
            throw new \FOSSBilling\Exception($errorMessage);
        }

        return $decodedResponse;
    }

    public function getNodes(): array
    {
        return $this->request('GET', '/api/application/nodes');
    }

    public function getNode(int $id): array
    {
        return $this->request('GET', '/api/application/nodes/' . $id);
    }

    public function getLocations(): array
    {
        return $this->request('GET', '/api/application/locations');
    }

    public function getEggs(?int $nestId = null): array
    {
        $endpoint = $nestId ? "/api/application/nests/{$nestId}/eggs" : '/api/application/nests?include=eggs';
        return $this->request('GET', $endpoint);
    }

    public function getEgg(int $nestId, int $eggId): array
    {
        return $this->request('GET', "/api/application/nests/{$nestId}/eggs/{$eggId}?include=variables");
    }

    public function createServer(array $data): array
    {
        return $this->request('POST', '/api/application/servers', $data);
    }
    
    public function updateServerBuild(int $serverId, array $data): array
    {
        return $this->request('PATCH', "/api/application/servers/{$serverId}/build", $data);
    }
    
    public function updateServerStartup(int $serverId, array $data): array
    {
        return $this->request('PUT', "/api/application/servers/{$serverId}/startup", $data);
    }

    public function suspendServer(int $serverId): void
    {
        $this->request('POST', "/api/application/servers/{$serverId}/suspend");
    }

    public function unsuspendServer(int $serverId): void
    {
        $this->request('POST', "/api/application/servers/{$serverId}/unsuspend");
    }

    public function deleteServer(int $serverId): void
    {
        $this->request('DELETE', "/api/application/servers/{$serverId}");
    }

    public function getServerDetails(int $serverId): array
    {
        return $this->request('GET', "/api/application/servers/{$serverId}");
    }
    
    /**
     * Get server by external_id (idempotent lookup)
     */
    public function getServerByExternalId(string $externalId): array
    {
        return $this->request('GET', "/api/application/servers/external/{$externalId}");
    }
    
    public function getServerStartup(int $serverId): array
    {
        return $this->request('GET', "/api/application/servers/{$serverId}/startup");
    }

    public function clientNetworkAllocations(string $uuidShort): array
    {
        return $this->request('GET', "/api/client/servers/{$uuidShort}/network/allocations");
    }
    
    public function clientNetworkAssignAllocation(string $uuidShort): array
    {
        return $this->request('POST', "/api/client/servers/{$uuidShort}/network/allocations");
    }
    
    public function clientNetworkSetPrimary(string $uuidShort, string $allocationId): array
    {
        return $this->request('POST', "/api/client/servers/{$uuidShort}/network/allocations/{$allocationId}/primary");
    }
    
    public function clientNetworkSetNote(string $uuidShort, string $allocationId, string $note): array
    {
        return $this->request('POST', "/api/client/servers/{$uuidShort}/network/allocations/{$allocationId}", ['notes' => $note]);
    }
    
    public function clientNetworkDelete(string $uuidShort, string $allocationId): array
    {
        return $this->request('DELETE', "/api/client/servers/{$uuidShort}/network/allocations/{$allocationId}");
    }
    public function getUsers(?string $filterEmail = null): array
    {
        $endpoint = '/api/application/users';
        if ($filterEmail) {
            $endpoint .= '?filter[email]=' . urlencode($filterEmail);
        }
        return $this->request('GET', $endpoint);
    }

    public function createUser(array $data): array
    {
        return $this->request('POST', '/api/application/users', $data);
    }
    
    public function updateUser(int $userId, array $data): array
    {
        return $this->request('PATCH', "/api/application/users/{$userId}", $data);
    }

    public function getAllocations(int $nodeId): array
    {
        // Pterodactyl pagination support might be needed for very large nodes, 
        // but for now we fetch the first page.
        // TODO: Implement pagination if needed.
        return $this->request('GET', "/api/application/nodes/{$nodeId}/allocations");
    }

    public function createAllocation(int $nodeId, array $data): array
    {
        return $this->request('POST', "/api/application/nodes/{$nodeId}/allocations", $data);
    }

    /**
     * Find a free allocation on a node or create one if possible.
     */
    public function findFreeAllocation(int $nodeId, int $startPort = 25565, ?string $preferredIp = null, ?int $endPort = null): array
    {
        $response = $this->getAllocations($nodeId);
        $allocations = $response['data'] ?? [];
        
        foreach ($allocations as $allocation) {
            if (empty($allocation['attributes']['assigned'])) {
                return [
                    'id' => $allocation['attributes']['id'],
                    'port' => $allocation['attributes']['port'],
                    'ip' => $allocation['attributes']['ip'],
                ];
            }
        }
        
        $usedPorts = [];
        foreach ($allocations as $allocation) {
            $usedPorts[] = $allocation['attributes']['port'];
        }
        
        $port = $startPort;
        $maxTries = 1000;
        
        for ($i = 0; $i < $maxTries; $i++) {
            if (!in_array($port, $usedPorts)) {
                break;
            }
            $port++;
            if ($endPort && $port > $endPort) {
                throw new \FOSSBilling\Exception('No free ports available within the specified range.');
            }
        }
        
        $targetIp = $preferredIp;
        if (!$targetIp) {
            $ips = array_map(function($a) { return $a['attributes']['ip']; }, $allocations);
            $counts = array_count_values($ips);
            arsort($counts);
            $targetIp = key($counts);
        }
        if (!$targetIp) {
            $node = $this->getNode($nodeId);
            $targetIp = $node['attributes']['fqdn'] ?? '0.0.0.0';
        }
        $allocationData = [
            'ip' => $targetIp,
            'ports' => [(string)$port]
        ];

        $response = $this->createAllocation($nodeId, $allocationData);
        
        if (!empty($response['data'][0]['attributes']['id'])) {
            return [
                'id' => $response['data'][0]['attributes']['id'],
                'port' => $response['data'][0]['attributes']['port'],
                'ip' => $response['data'][0]['attributes']['ip'],
            ];
        }
        
        throw new \FOSSBilling\Exception('Failed to create new allocation.');
    }

    /**
     * Find multiple free allocations on a node (does not create new ones).
     * Returns up to $count unassigned allocations, preferring a specific IP if provided.
     */
    public function findFreeAllocations(int $nodeId, int $count, ?string $preferredIp = null): array
    {
        $response = $this->getAllocations($nodeId);
        $allocations = $response['data'] ?? [];
        $free = [];
        foreach ($allocations as $allocation) {
            $attr = $allocation['attributes'];
            if (empty($attr['assigned'])) {
                $free[] = [
                    'id' => $attr['id'],
                    'port' => $attr['port'],
                    'ip' => $attr['ip'],
                ];
            }
        }
        if ($preferredIp) {
            $preferred = array_values(array_filter($free, function($a) use ($preferredIp) {
                return $a['ip'] === $preferredIp;
            }));
            if (count($preferred) >= $count) {
                usort($preferred, function($a, $b) {
                    return $a['port'] <=> $b['port'];
                });
                return array_slice($preferred, 0, $count);
            }
            // Fallback: take as many preferred as possible, then fill from others
            $others = array_values(array_filter($free, function($a) use ($preferredIp) {
                return $a['ip'] !== $preferredIp;
            }));
            usort($preferred, function($a, $b) { return $a['port'] <=> $b['port']; });
            usort($others, function($a, $b) { return [$a['ip'], $a['port']] <=> [$b['ip'], $b['port']]; });
            $merged = array_merge($preferred, $others);
            if (count($merged) < $count) {
                throw new \FOSSBilling\Exception('Not enough free allocations available on node.');
            }
            return array_slice($merged, 0, $count);
        }
        usort($free, function($a, $b) {
            return [$a['ip'], $a['port']] <=> [$b['ip'], $b['port']];
        });
        if (count($free) < $count) {
            throw new \FOSSBilling\Exception('Not enough free allocations available on node.');
        }
        return array_slice($free, 0, $count);
    }


    /**
     * Get SSO redirect URL from WemX plugin
     */
    public function getSSORedirect(int $userId, string $ssoSecret): string
    {
        // WemX SSO endpoint expects query parameters
        $endpoint = '/sso-wemx/?sso_secret=' . urlencode($ssoSecret) . '&user_id=' . $userId;
        
        // Use standard request method
        // Note: The endpoint might not require Bearer token, but sending it shouldn't hurt
        $response = $this->request('GET', $endpoint);
        
        if (isset($response['redirect'])) {
            return $response['redirect'];
        }
        
        throw new \FOSSBilling\Exception('Failed to get SSO redirect URL: ' . ($response['message'] ?? 'Unknown error'));
    }
}
