<?php

namespace munkireport\module\applecare;

use Symfony\Component\Yaml\Yaml;

class Applecare_helper
{
    /**
     * Get Munki ClientID for a serial number
     *
     * @param string $serial_number
     * @return string|null ClientID or null if not found
     */
    private function getClientId($serial_number)
    {
        try {
            $machine = new \Model();
            $sql = "SELECT munkiinfo_value 
                    FROM munkiinfo 
                    WHERE serial_number = ? 
                    AND munkiinfo_key = 'ClientIdentifier' 
                    LIMIT 1";
            $result = $machine->query($sql, [$serial_number]);
            if (!empty($result) && isset($result[0])) {
                return $result[0]->munkiinfo_value ?? null;
            }
        } catch (\Exception $e) {
            // Silently fail - ClientID is optional
        }
        return null;
    }

    /**
     * Get org-specific AppleCare config with fallback
     *
     * @param string $serial_number
     * @return array|null
     */
    private function getAppleCareConfig($serial_number)
    {
        $client_id = $this->getClientId($serial_number);

        $api_url = null;
        $client_assertion = null;
        $rate_limit = 20;

        if (!empty($client_id)) {
            $parts = explode('-', $client_id, 2);
            $prefix = strtoupper($parts[0]);

            $org_api_url_key = $prefix . '_APPLECARE_API_URL';
            $org_assertion_key = $prefix . '_APPLECARE_CLIENT_ASSERTION';
            $org_rate_limit_key = $prefix . '_APPLECARE_RATE_LIMIT';

            $api_url = getenv($org_api_url_key);
            $client_assertion = getenv($org_assertion_key);
            $org_rate_limit = getenv($org_rate_limit_key);

            if (!empty($org_rate_limit)) {
                $rate_limit = (int)$org_rate_limit;
            }
        }

        if (empty($api_url)) {
            $api_url = getenv('APPLECARE_API_URL');
        }
        if (empty($client_assertion)) {
            $client_assertion = getenv('APPLECARE_CLIENT_ASSERTION');
        }
        $default_rate_limit = getenv('APPLECARE_RATE_LIMIT');
        if (!empty($default_rate_limit)) {
            $rate_limit = (int)$default_rate_limit ?: 20;
        }

        if (empty($api_url) || empty($client_assertion)) {
            return null;
        }

        return [
            'api_url' => $api_url,
            'client_assertion' => $client_assertion,
            'rate_limit' => $rate_limit
        ];
    }

    /**
     * Load reseller config and translate ID to name
     *
     * @param string $reseller_id
     * @return string|null
     */
    private function getResellerName($reseller_id)
    {
        if (empty($reseller_id)) {
            return null;
        }

        $config_path = APP_ROOT . '/local/module_configs/applecare_resellers.yml';
        if (!file_exists($config_path)) {
            return $reseller_id;
        }

        try {
            $config = Yaml::parseFile($config_path);
            
            // Try exact match first
            if (isset($config[$reseller_id])) {
                return $config[$reseller_id];
            }
            
            // Try case-insensitive match
            $reseller_id_upper = strtoupper($reseller_id);
            foreach ($config as $key => $value) {
                if (strtoupper($key) === $reseller_id_upper) {
                    return $value;
                }
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Error loading reseller config: ' . $e->getMessage());
        }

        return $reseller_id;
    }

    /**
     * Generate access token from client assertion
     *
     * @param string $client_assertion
     * @param string $api_base_url
     * @return string
     */
    private function generateAccessToken($client_assertion, $api_base_url)
    {
        $client_assertion = trim($client_assertion);
        $client_assertion = preg_replace('/\s+/', '', $client_assertion);
        $client_assertion = trim($client_assertion, '"\'');

        $parts = explode('.', $client_assertion);
        if (count($parts) !== 3) {
            throw new \Exception('Invalid client assertion format. Expected JWT token with 3 parts.');
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $client_id = $payload['sub'] ?? null;

        if (empty($client_id)) {
            throw new \Exception('Could not extract client ID from assertion.');
        }

        $scope = 'business.api';
        if (strpos($api_base_url, 'api-school') !== false) {
            $scope = 'school.api';
        }

        $ch = curl_init('https://account.apple.com/auth/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Host: account.apple.com',
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $client_id,
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $client_assertion,
                'scope' => $scope
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Temporary logging for all fetches
        error_log("AppleCare FETCH: Token generation - HTTP {$http_code}");

        if ($curl_error) {
            throw new \Exception("cURL error: {$curl_error}");
        }

        if ($http_code !== 200) {
            throw new \Exception("Failed to get access token: HTTP $http_code - $response");
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new \Exception("No access token in response: $response");
        }

        return $data['access_token'];
    }

    /**
     * Sync a single device
     *
     * @param string $serial_number
     * @param string $api_base_url
     * @param string $access_token
     * @param callable|null $outputCallback Optional callback for progress updates (message, isError)
     * @return array ['success' => bool, 'records' => int, 'requests' => int, 'message' => string, 'rate_limit' => int|null, 'rate_limit_remaining' => int|null]
     */
    public function syncSingleDevice($serial_number, $api_base_url, $access_token, $outputCallback = null)
    {
        if ($outputCallback === null) {
            $outputCallback = function($message, $isError = false) {};
        }

        $requests = 0;
        $device_info = [];
        $device_attrs = [];
        $detected_rate_limit = null;
        $detected_rate_limit_remaining = null;

        // First, fetch device information
        $device_url = $api_base_url . "orgDevices/{$serial_number}";
        
        $ch = curl_init($device_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in response
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $device_response = curl_exec($ch);
        $device_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $device_curl_error = curl_error($ch);
        
        // Extract body from response (since CURLOPT_HEADER is true)
        $device_header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $device_body = substr($device_response, $device_header_size);
        
        curl_close($ch);
        $requests++;

        // Temporary logging for all fetches
        error_log("AppleCare FETCH: Device lookup for {$serial_number} - URL: {$device_url} - HTTP {$device_http_code}");

        // If device not found in ABM, skip immediately
        if ($device_http_code === 404) {
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => 'SKIP (HTTP 404) - Device not found in Apple Business/School Manager', 'rate_limit' => null, 'rate_limit_remaining' => null];
        }

        // Extract device information if available
        if ($device_http_code === 200) {
            if ($device_curl_error) {
                error_log("AppleCare: Device lookup cURL error for {$serial_number}: {$device_curl_error}");
            } else {
                $device_data = json_decode($device_body, true);
                
                if (isset($device_data['data']['attributes'])) {
                    $device_attrs = $device_data['data']['attributes'];
                    
                    // Map available fields from Apple Business Manager API
                    $device_info = [
                        'serial_number' => $serial_number,
                        'model' => $device_attrs['deviceModel'] ?? null,
                        'part_number' => $device_attrs['partNumber'] ?? null,
                        'product_family' => $device_attrs['productFamily'] ?? null,
                        'product_type' => $device_attrs['productType'] ?? null,
                        'color' => $device_attrs['color'] ?? null,
                        'device_capacity' => $device_attrs['deviceCapacity'] ?? null,
                        'device_assignment_status' => $device_attrs['status'] ?? null,
                        'purchase_source_type' => $device_attrs['purchaseSourceType'] ?? null,
                        'purchase_source_id' => $device_attrs['purchaseSourceId'] ?? null,
                        'order_number' => $device_attrs['orderNumber'] ?? null,
                        'order_date' => null,
                        'added_to_org_date' => null,
                        'released_from_org_date' => null,
                        'wifi_mac_address' => $device_attrs['wifiMacAddress'] ?? null,
                        'ethernet_mac_address' => null,
                        'bluetooth_mac_address' => $device_attrs['bluetoothMacAddress'] ?? null,
                    ];
                    
                    // Handle dates
                    if (!empty($device_attrs['orderDateTime'])) {
                        $device_info['order_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['orderDateTime']));
                    }
                    if (!empty($device_attrs['addedToOrgDateTime'])) {
                        $device_info['added_to_org_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['addedToOrgDateTime']));
                    }
                    if (!empty($device_attrs['releasedFromOrgDateTime'])) {
                        $device_info['released_from_org_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['releasedFromOrgDateTime']));
                    }
                    
                    // Handle array fields
                    if (!empty($device_attrs['ethernetMacAddress']) && is_array($device_attrs['ethernetMacAddress'])) {
                        $device_info['ethernet_mac_address'] = implode(', ', array_filter($device_attrs['ethernetMacAddress']));
                    }
                } else {
                    // HTTP 200 but unexpected JSON structure
                    error_log("AppleCare: Device lookup returned 200 for {$serial_number} but JSON structure unexpected. Response: " . substr($device_body, 0, 500));
                }
            }
        } else {
            // Non-200 response - log warning but continue to fetch coverage
            if ($device_http_code !== 404) {
                error_log("AppleCare: Device lookup failed for {$serial_number} with HTTP {$device_http_code}, but continuing to fetch coverage");
            }
        }

        // Call Apple API for AppleCare coverage
        $url = $api_base_url . "orgDevices/{$serial_number}/appleCareCoverage";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in response
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        
        // Get response headers for rate limit information
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        curl_close($ch);
        $requests++;

        // Temporary logging for all fetches
        error_log("AppleCare FETCH: Coverage for {$serial_number} - URL: {$url} - HTTP {$http_code}");

        if ($curl_error) {
            // HTTP/2 errors - retry once
            if ($curl_errno == 92 || $curl_errno == 16) {
                sleep(2);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json',
                ]);
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers = substr($response, 0, $header_size);
                $body = substr($response, $header_size);
                curl_close($ch);
                $requests++;

                // Temporary logging for all fetches (retry)
                error_log("AppleCare FETCH: Coverage for {$serial_number} - URL: {$url} - HTTP {$http_code} (RETRY)");

                if ($curl_error) {
                    throw new \Exception("cURL error after retry: {$curl_error}");
                }
            } else {
                throw new \Exception("cURL error: {$curl_error} (errno: {$curl_errno})");
            }
        }

        // Parse rate limit headers from successful responses to track limits dynamically
        if ($http_code === 200 && !empty($headers)) {
            $header_lines = explode("\r\n", $headers);
            foreach ($header_lines as $header_line) {
                // Check for various rate limit header formats
                if (stripos($header_line, 'X-RateLimit-Limit:') === 0) {
                    $detected_rate_limit = (int)trim(substr($header_line, 18));
                } elseif (stripos($header_line, 'X-Rate-Limit-Limit:') === 0) {
                    $detected_rate_limit = (int)trim(substr($header_line, 20));
                } elseif (stripos($header_line, 'X-RateLimit-Remaining:') === 0) {
                    $detected_rate_limit_remaining = (int)trim(substr($header_line, 22));
                } elseif (stripos($header_line, 'X-Rate-Limit-Remaining:') === 0) {
                    $detected_rate_limit_remaining = (int)trim(substr($header_line, 24));
                }
            }
        }

        // Handle HTTP 429 (Rate Limit) with Retry-After header
        if ($http_code === 429) {
            $retry_after = null;
            $rate_limit_reset = null;
            
            // Parse headers for rate limit information
            if (!empty($headers)) {
                $header_lines = explode("\r\n", $headers);
                foreach ($header_lines as $header_line) {
                    if (stripos($header_line, 'Retry-After:') === 0) {
                        $retry_after = (int)trim(substr($header_line, 12));
                    } elseif (stripos($header_line, 'X-RateLimit-Reset:') === 0) {
                        $rate_limit_reset = (int)trim(substr($header_line, 18));
                    } elseif (stripos($header_line, 'X-Rate-Limit-Reset:') === 0) {
                        $rate_limit_reset = (int)trim(substr($header_line, 19));
                    }
                }
            }
            
            // Use Retry-After if provided, otherwise default to 60 seconds
            $wait_time = $retry_after ?: 60;
            
            $error_msg = "SKIP (HTTP 429 - Rate limit exceeded)";
            if ($retry_after) {
                $error_msg .= " - Retry after {$retry_after}s";
            }
            if ($rate_limit_reset) {
                $reset_time = date('Y-m-d H:i:s', $rate_limit_reset);
                $error_msg .= " - Rate limit resets at {$reset_time}";
            }
            
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => $error_msg, 'retry_after' => $wait_time];
        }

        if ($http_code !== 200) {
            $error_msg = "SKIP (HTTP $http_code)";
            if ($http_code === 404) {
                $error_msg .= " - Device not found in Apple Business/School Manager or not enrolled";
            } elseif ($http_code === 401) {
                $error_msg .= " - Authentication failed (token may be expired)";
            } elseif ($http_code === 403) {
                $error_msg .= " - Access forbidden (check API permissions)";
            }

            if (!empty($body)) {
                $error_data = json_decode($body, true);
                if (isset($error_data['errors']) && is_array($error_data['errors'])) {
                    $error_details = [];
                    foreach ($error_data['errors'] as $error) {
                        if (isset($error['detail'])) {
                            $error_details[] = $error['detail'];
                        } elseif (isset($error['title'])) {
                            $error_details[] = $error['title'];
                        }
                    }
                    if (!empty($error_details)) {
                        $error_msg .= " - " . implode(", ", $error_details);
                    }
                }
            }

            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => $error_msg];
        }

        $data = json_decode($body, true);

        if (!isset($data['data']) || empty($data['data'])) {
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => 'SKIP (no coverage)'];
        }

        // Save coverage data with device information
        // Only update last_fetched when we actually fetch and save coverage data
        $fetch_timestamp = time();
        $records_saved = 0;
        foreach ($data['data'] as $coverage) {
            $attrs = $coverage['attributes'] ?? [];

            // Use API's updatedDateTime if available, otherwise set to NULL
            $last_updated = null;
            if (!empty($attrs['updatedDateTime'])) {
                $last_updated = strtotime($attrs['updatedDateTime']);
            } elseif (!empty($device_attrs['updatedDateTime'])) {
                $last_updated = strtotime($device_attrs['updatedDateTime']);
            }
            
            $coverage_data = array_merge($device_info, [
                'id' => $coverage['id'],
                'serial_number' => $serial_number,
                'description' => $attrs['description'] ?? '',
                'status' => $attrs['status'] ?? '',
                'agreementNumber' => $attrs['agreementNumber'] ?? '',
                'paymentType' => $attrs['paymentType'] ?? '',
                'isRenewable' => !empty($attrs['isRenewable']) ? 1 : 0,
                'isCanceled' => !empty($attrs['isCanceled']) ? 1 : 0,
                'startDateTime' => !empty($attrs['startDateTime']) ? date('Y-m-d', strtotime($attrs['startDateTime'])) : null,
                'endDateTime' => !empty($attrs['endDateTime']) ? date('Y-m-d', strtotime($attrs['endDateTime'])) : null,
                'contractCancelDateTime' => !empty($attrs['contractCancelDateTime']) ? date('Y-m-d', strtotime($attrs['contractCancelDateTime'])) : null,
                'last_updated' => $last_updated,
                'last_fetched' => $fetch_timestamp, // Use the timestamp we set earlier
            ]);

            // Normalize boolean fields
            foreach (['isRenewable', 'isCanceled'] as $field) {
                if (isset($coverage_data[$field])) {
                    $coverage_data[$field] = ($coverage_data[$field] === true ||
                         $coverage_data[$field] === 1 ||
                         $coverage_data[$field] === '1' ||
                         strtolower($coverage_data[$field]) === 'true') ? 1 : 0;
                }
            }

            // Translate reseller ID to name if config exists
            if (!empty($coverage_data['purchase_source_id'])) {
                $resellerName = $this->getResellerName($coverage_data['purchase_source_id']);
                // Only set purchase_source_name if we found a translation (not just the ID)
                if ($resellerName && $resellerName !== $coverage_data['purchase_source_id']) {
                    $coverage_data['purchase_source_name'] = $resellerName;
                    $coverage_data['purchase_source_id_display'] = $coverage_data['purchase_source_id'];
                }
            }

            // Insert or update
            \Applecare_model::updateOrCreate(
                ['id' => $coverage['id']],
                $coverage_data
            );
            $records_saved++;
        }

        return [
            'success' => true, 
            'records' => $records_saved, 
            'requests' => $requests, 
            'message' => '',
            'rate_limit' => $detected_rate_limit,
            'rate_limit_remaining' => $detected_rate_limit_remaining
        ];
    }

    /**
     * Sync AppleCare data for a single serial number
     *
     * @param string $serial_number
     * @return array
     */
    public function syncSerial($serial_number)
    {
        if (empty($serial_number) || strlen($serial_number) < 8) {
            return ['success' => false, 'records' => 0, 'message' => 'Invalid serial number'];
        }

        try {
            $device_config = $this->getAppleCareConfig($serial_number);
            if (!$device_config) {
                return ['success' => false, 'records' => 0, 'message' => 'AppleCare API not configured'];
            }

            $api_base_url = $device_config['api_url'];
            $client_assertion = $device_config['client_assertion'];

            if (substr($api_base_url, -1) !== '/') {
                $api_base_url .= '/';
            }

            $access_token = $this->generateAccessToken($client_assertion, $api_base_url);
            return $this->syncSingleDevice($serial_number, $api_base_url, $access_token);
        } catch (\Exception $e) {
            return ['success' => false, 'records' => 0, 'message' => 'Sync failed: ' . $e->getMessage()];
        }
    }
}
