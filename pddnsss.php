<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;

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
    
    $domainsToUpdate = json_decode($_ENV['CLOUDFLARE_DOMAINS_JSON'], true);;
    
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
    $ovhClient = new Client();
    
    try {
        echo "🗑️ Removing old IP ($oldIp) from whitelist...\n";
        $response = $ovhClient->request(
            'DELETE',
            sprintf(
                'https://eu.api.ovh.com/v1/hosting/privateDatabase/%s/whitelist/%s%%2F32',
                $_ENV['OVH_DATABASE_SERVICE_NAME'],
                $oldIp
            ),
            [
                'headers' => ['Authorization' => "Bearer {$_ENV['OVH_BEARER_TOKEN']}"],
            ]
        );
        
        if ($response->getStatusCode() !== 200) {
            echo "❌ Error trying to remove IP: $oldIp\n";
            
            return;
        }
        
        echo "➕ Adding new IP ($newIp) to whitelist...\n";
        $dnsRecord = $ovhClient->request(
            'POST',
            sprintf(
                'https://eu.api.ovh.com/v1/hosting/privateDatabase/%s/whitelist',
                $_ENV['OVH_DATABASE_SERVICE_NAME']
            ),
            [
                'headers' => ['Authorization' => "Bearer {$_ENV['OVH_BEARER_TOKEN']}"],
                'json' => [
                    'ip' => $newIp,
                    'name' => 'Added via PDDNSSS',
                    'service' => true,
                    'sftp' => true,
                ],
            ]
        );
        
        if ($dnsRecord->getStatusCode() !== 200) {
            echo "❌ Error trying to add IP: $newIp\n";
            
            return;
        }
        
        echo "✅ OVH whitelist updated successfully\n";
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
    return file_get_contents('ip.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

function setStoredIp(string $ip): void
{
    file_put_contents('ip.txt', $ip, LOCK_EX);
    echo "💾 Stored IP updated to: $ip\n";
}

main();