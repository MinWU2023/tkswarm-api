<?php
declare(strict_types=1);

namespace App\Core\Schedule;

class Scheduler
{
    private array $commands = [];

    public function register(Command $command): self
    {
        $this->commands[$command->getSignature()] = $command;
        return $this;
    }

    public function run(array $argv): int
    {
        $commandName = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if ($commandName === 'list' || $commandName === '--help') {
            $this->showHelp();
            return 0;
        }

        if (!isset($this->commands[$commandName])) {
            echo "\033[31mCommand [{$commandName}] not registered.\033[0m\n";
            $this->showHelp();
            return 1;
        }

        /** @var Command $command */
        $command = $this->commands[$commandName];
        return $command->handle($args);
    }

    private function showHelp(): void
    {
        echo "=========================================================\n";
        echo " TKSwarm CLI & Schedule Task Runner (PHP8.2+)\n";
        echo "=========================================================\n";
        echo "Usage: php artisan [command] [options]\n\n";
        echo "Available commands:\n";
        foreach ($this->commands as $sig => $cmd) {
            printf("  \033[32m%-24s\033[0m %s\n", $sig, $cmd->getDescription());
        }
        echo "\n";
    }
}
