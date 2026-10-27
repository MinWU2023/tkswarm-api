<?php
declare(strict_types=1);

namespace App\Commands;

use App\Core\Schedule\Command;
use App\Core\Database\DB;

class ProxyProbeCommand extends Command
{
    protected string $signature = 'proxy:probe';
    protected string $description = '对全量代理IP进行连通性检测与延迟测速 (Proxy Probe)';

    public function handle(array $args = []): int
    {
        $this->info("Scanning proxies for probe & latency detection...");

        $proxies = DB::table('proxies')->get();
        if (empty($proxies)) {
            $this->warn("No proxies found in database.");
            return 0;
        }

        $this->info("Probing " . count($proxies) . " proxy server(s)...");
        foreach ($proxies as $p) {
            $host = $p['host'];
            $port = (int)$p['port'];

            $start = microtime(true);
            $fp = @fsockopen($host, $port, $errno, $errstr, 2.0);
            $latency = (int)round((microtime(true) - $start) * 1000);

            if ($fp) {
                fclose($fp);
                DB::table('proxies')->where('id', $p['id'])->update([
                    'status' => 'available',
                    'latency_ms' => $latency,
                    'last_checked_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $this->info("Proxy [{$host}:{$port}] -> AVAILABLE ({$latency}ms)");
            } else {
                DB::table('proxies')->where('id', $p['id'])->update([
                    'status' => 'unavailable',
                    'latency_ms' => 9999,
                    'last_checked_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $this->warn("Proxy [{$host}:{$port}] -> UNAVAILABLE ({$errstr})");
            }
        }

        $this->info("Proxy probing job finished.");
        return 0;
    }
}
