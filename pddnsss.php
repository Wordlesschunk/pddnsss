<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Ovh\Api;

function main(): void
{
    $dotEnv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotEnv->load();
    
    $storedIp = getStoredIp();
    $publicIp = getPublicIp();
    
    echo "🔍 Checking IP addresses...\n";
    echo "📄 Stored IP: $storedIp\n";
    echo "🌐 Public IP: $publicIp\n";
    
    if (!$storedIp) {
        setStoredIp($publicIp);
        echo "✅ New IP address stored: $publicIp\n";
        
        return;
    }
    
    if ($storedIp === $publicIp) {
        echo "ℹ️ IP is unchanged. Exiting.\n";
        
        return;
    }
    
    try {
        echo "🔄 IP has changed, updating services...\n";
        setStoredIp($publicIp);
        
        updateOVHWebCloudDatabaseWhitelist($storedIp, $publicIp);
        echo "✅ Updated OVH whitelist IP\n";
        updateCloudflareDNSRecords();
        echo "✅ Updated Cloudflare DNS records\n";
        echo "✨ All updates completed successfully!\n";
        
        return;
    } catch (Exception $e) {
        echo "❌ Error occurred during updates: ".$e->getMessage()."\n";
    }
}

function updateCloudflareDNSRecords(): void
{
    $cloudflareClient = new Client();
    
    $domainsToUpdate = json_decode($_ENV['CLOUDFLARE_DOMAINS_JSON'], true);
    
    echo "📡 Updating Cloudflare DNS records...\n";
    
    foreach ($domainsToUpdate as $domain => $data) {
        $zoneId = $data['zone_id'];
        
        try {
            echo "🔄 Processing domain: $domain\n";
            
            $dnsRecord = $cloudflareClient->request(
                'GET',
                sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records?type=A&name=%s', $zoneId, $domain),
                [
                    'headers' => ['Authorization' => "Bearer {$_ENV['CLOUDFLARE_API_TOKEN']}"],
                ]
            );
            
            $responseData = json_decode($dnsRecord->getBody()->getContents(), true);
            
            if (empty($responseData['result'][0]['id'])) {
                echo "⚠️ No A record found for domain: $domain\n";
                continue;
            }
            
            $dnsRecordId = $responseData['result'][0]['id'];
            
            $cloudflareClient->request(
                'PUT',
                sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records/%s', $zoneId, $dnsRecordId),
                [
                    'headers' => ['Authorization' => "Bearer {$_ENV['CLOUDFLARE_API_TOKEN']}"],
                    'json' => [
                        'type' => 'A',
                        'name' => $domain,
                        'content' => getPublicIp(),
                        'ttl' => 1,
                        'proxied' => false,
                        'comment' => 'Added via PDDNSSS @ '.date('Y-m-d H:i:s')
                    ],
                ]
            );
            
            echo "✅ Updated A record for: $domain\n";
            
            continue;
        } catch (Exception $e) {
            echo "❌ Error updating $domain: ".$e->getMessage()."\n";
        }
    }
}

function updateOVHWebCloudDatabaseWhitelist(string $oldIp, $newIp): void
{
    $http_client = new Client([
        'timeout'         => 30,
        'connect_timeout' => 5,
    ]);
    
    // Set up OVH API client with your API credentials
    $ovh = new Api(
        $_ENV['OVH_APPLICATION_KEY'],
        $_ENV['OVH_APPLICATION_SECRET'],
        $_ENV['OVH_ENDPOINT'],
        $_ENV['OVH_CONSUMER_KEY'],
        $http_client
    );
    try {
        echo "🗑️ Removing old IP ($oldIp) from whitelist...\n";
        
        // Use the OVH API to remove the old IP from the whitelist
        $cidrIp = strpos($oldIp, ':') !== false ? $oldIp . '/128' : $oldIp . '/32';
        $encodedCidrIp = rawurlencode($cidrIp);
        
        $response = $ovh->delete('/hosting/privateDatabase/'.$_ENV['OVH_DATABASE_SERVICE_NAME'].'/whitelist/'.$encodedCidrIp);
        
        if ($response) {
            echo "✅ Removed old IP from OVH whitelist\n";
        }
        
        echo "➕ Adding new IP ($newIp) to whitelist...\n";
        
        // Add the new IP to the whitelist
        $response = $ovh->post('/hosting/privateDatabase/'.$_ENV['OVH_DATABASE_SERVICE_NAME'].'/whitelist', [
            'ip' => $newIp,
            'name' => 'Added via PDDNSSS @ '.date('Y-m-d H:i:s'),
            'service' => true,
            'sftp' => true,
        ]);
        
        if ($response) {
            echo "✅ New IP added to OVH whitelist\n";
        }
        
    } catch (Exception $e) {
        echo "❌ OVH update error: ".$e->getMessage()."\n";
    }
}

function getPublicIp(): string
{
    echo "🌐 Fetching public IP address...\n";
    $externalContent = file_get_contents('http://checkip.dyndns.com/');
    preg_match('/Current IP Address: \[?([:.0-9a-fA-F]+)\]?/', $externalContent, $m);
    
    return $m[1];
}

function getStoredIp(): string
{
    return trim(file_get_contents('ip.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

function setStoredIp(string $ip): void
{
    file_put_contents('ip.txt', $ip, LOCK_EX);
    echo "💾 Stored IP updated to: $ip\n";
}

main();
