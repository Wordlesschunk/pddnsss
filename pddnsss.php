<?php

require 'vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Ovh\Api;

final class Log
{
    private static bool $timestamps = true;

    private static function prefix(string $level): string
    {
        if (!self::$timestamps) {
            return sprintf("[%s]", $level);
        }

        return sprintf("[%s] [%s]", date('Y-m-d H:i:s'), $level);
    }

    public static function info(string $message): void
    {
        echo self::prefix('INFO') . " " . $message . PHP_EOL;
    }

    public static function ok(string $message): void
    {
        echo self::prefix('OK') . "   " . $message . PHP_EOL;
    }

    public static function warn(string $message): void
    {
        echo self::prefix('WARN') . " " . $message . PHP_EOL;
    }

    public static function error(string $message): void
    {
        echo self::prefix('ERROR') . " " . $message . PHP_EOL;
    }
}

function main(): void
{
    Dotenv::createImmutable(__DIR__)->load();

    $storedIp = getStoredIp();
    $publicIp = getPublicIp();

    Log::info("IP check: stored=" . ($storedIp ?: 'none') . " public={$publicIp}");

    if (!$storedIp) {
        setStoredIp($publicIp);
        Log::ok("Stored initial IP: {$publicIp}");
        return;
    }

    if ($storedIp === $publicIp) {
        Log::ok("No change. Nothing to do.");
        return;
    }

    Log::info("IP changed: {$storedIp} -> {$publicIp}");
    setStoredIp($publicIp);

    try {
        updateOVHWebCloudDatabaseWhitelist($storedIp, $publicIp);
        Log::ok("OVH whitelist updated");

        updateCloudflareDNSRecords($publicIp);
        Log::ok("Cloudflare DNS updated");

        Log::ok("Done.");
    } catch (Exception $e) {
        Log::error("Update failed: " . $e->getMessage());
        exit(1);
    }
}

function updateCloudflareDNSRecords(string $newIp): void
{
    $cloudflareClient = new Client();

    $domainsToUpdate = json_decode($_ENV['CLOUDFLARE_DOMAINS_JSON'], true);
    if (!is_array($domainsToUpdate)) {
        throw new RuntimeException('CLOUDFLARE_DOMAINS_JSON is not valid JSON.');
    }

    $failures = [];

    foreach ($domainsToUpdate as $domain => $data) {
        $zoneId = $data['zone_id'] ?? null;
        if (!$zoneId) {
            $failures[] = "{$domain}: missing zone_id";
            Log::error("Cloudflare failed for {$domain}: missing zone_id");
            continue;
        }

        try {
            $dnsRecord = $cloudflareClient->request(
                'GET',
                sprintf(
                    'https://api.cloudflare.com/client/v4/zones/%s/dns_records?type=A&name=%s',
                    $zoneId,
                    $domain
                ),
                [
                    'headers' => [
                        'Authorization' => "Bearer {$_ENV['CLOUDFLARE_API_TOKEN']}",
                    ],
                ]
            );

            $responseData = json_decode($dnsRecord->getBody()->getContents(), true);
            $dnsRecordId = $responseData['result'][0]['id'] ?? null;

            if (!$dnsRecordId) {
                throw new RuntimeException("No A record found");
            }

            $cloudflareClient->request(
                'PUT',
                sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records/%s', $zoneId, $dnsRecordId),
                [
                    'headers' => [
                        'Authorization' => "Bearer {$_ENV['CLOUDFLARE_API_TOKEN']}",
                    ],
                    'json' => [
                        'type' => 'A',
                        'name' => $domain,
                        'content' => $newIp,
                        'ttl' => 1,
                        'proxied' => false,
                        'comment' => 'Updated by PDDNSSS @ ' . date('Y-m-d H:i:s'),
                    ],
                ]
            );

            Log::ok("Updated A record: {$domain} -> {$newIp}");
        } catch (Exception $e) {
            $failures[] = "{$domain}: " . $e->getMessage();
            Log::error("Cloudflare failed for {$domain}: " . $e->getMessage());
        }
    }

    if ($failures !== []) {
        throw new RuntimeException(
            "Cloudflare update had failures:\n- " . implode("\n- ", $failures)
        );
    }
}

function updateOVHWebCloudDatabaseWhitelist(string $oldIp, string $newIp): void
{
    $httpClient = new Client([
        'timeout' => 30,
        'connect_timeout' => 5,
    ]);

    $ovh = new Api(
        $_ENV['OVH_APPLICATION_KEY'],
        $_ENV['OVH_APPLICATION_SECRET'],
        $_ENV['OVH_ENDPOINT'],
        $_ENV['OVH_CONSUMER_KEY'],
        $httpClient
    );

    $serviceName = $_ENV['OVH_DATABASE_SERVICE_NAME'];

    // Remove old IP (best-effort; don't fail the whole run if it wasn't present)
    $cidrOld = (strpos($oldIp, ':') !== false) ? ($oldIp . '/128') : ($oldIp . '/32');
    $encodedOld = rawurlencode($cidrOld);

    try {
        Log::info("OVH: removing old whitelist IP {$cidrOld}");
        $ovh->delete("/hosting/privateDatabase/{$serviceName}/whitelist/{$encodedOld}");
        Log::ok("OVH: removed old IP");
    } catch (Exception $e) {
        Log::warn("OVH: could not remove old IP ({$cidrOld}): " . $e->getMessage());
    }

    // Add new IP (must succeed)
    try {
        Log::info("OVH: adding new whitelist IP {$newIp}");
        $ovh->post("/hosting/privateDatabase/{$serviceName}/whitelist", [
            'ip' => $newIp,
            'name' => 'Updated by PDDNSSS @ ' . date('Y-m-d H:i:s'),
            'service' => true,
            'sftp' => true,
        ]);
        Log::ok("OVH: added new IP");
    } catch (Exception $e) {
        throw new RuntimeException("OVH add failed: " . $e->getMessage(), 0, $e);
    }
}

function getPublicIp(): string
{
    $externalContent = @file_get_contents('http://checkip.dyndns.com/');
    if ($externalContent === false) {
        throw new RuntimeException('Failed to fetch public IP (checkip.dyndns.com unreachable).');
    }

    if (!preg_match('/Current IP Address: \[?([:.0-9a-fA-F]+)\]?/', $externalContent, $m)) {
        throw new RuntimeException('Failed to parse public IP response.');
    }

    return $m[1];
}

function getStoredIp(): string
{
    if (!is_file('ip.txt')) {
        return '';
    }

    return trim((string) file_get_contents('ip.txt'));
}

function setStoredIp(string $ip): void
{
    file_put_contents('ip.txt', $ip . PHP_EOL, LOCK_EX);
}

main();
