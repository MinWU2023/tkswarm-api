<?php
declare(strict_types=1);

namespace App\Core\Schedule;

abstract class Command
{
    protected string $signature = '';
    protected string $description = '';

    abstract public function handle(array $args = []): int;

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    protected function info(string $text): void
    {
        echo "\033[32m[INFO] " . date('Y-m-d H:i:s') . " " . $text . "\033[0m\n";
    }

    protected function warn(string $text): void
    {
        echo "\033[33m[WARN] " . date('Y-m-d H:i:s') . " " . $text . "\033[0m\n";
    }

    protected function error(string $text): void
    {
        echo "\033[31m[ERROR] " . date('Y-m-d H:i:s') . " " . $text . "\033[0m\n";
    }
}
