<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:maintenance',
    description: 'Manage maintenance mode',
)]
class MaintenanceCommand extends Command
{
    private $params;
    private $maintenanceFilePath;

    public function __construct(ParameterBagInterface $params)
    {
        parent::__construct();
        $this->params = $params;
        $this->maintenanceFilePath = $params->get('kernel.project_dir') . '/maintenance.lock';
    }

    protected function configure(): void
    {
        $this
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable maintenance mode')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable maintenance mode')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show maintenance mode status')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Duration of maintenance in minutes')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Custom maintenance message');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Show status
        if ($input->getOption('status')) {
            if (file_exists($this->maintenanceFilePath)) {
                $data = json_decode(file_get_contents($this->maintenanceFilePath), true);
                $io->success('Maintenance mode is enabled');
                $io->table(
                    ['Property', 'Value'],
                    [
                        ['Start Time', $data['start_time'] ?? 'N/A'],
                        ['End Time', $data['end_time'] ?? 'N/A'],
                        ['Message', $data['message'] ?? 'N/A'],
                    ]
                );
            } else {
                $io->info('Maintenance mode is disabled');
            }
            return Command::SUCCESS;
        }

        // Enable maintenance mode
        if ($input->getOption('enable')) {
            if (file_exists($this->maintenanceFilePath)) {
                $io->error('Maintenance mode is already enabled');
                return Command::FAILURE;
            }

            $duration = $input->getOption('duration') ?? 30; // Default 30 minutes
            $message = $input->getOption('message') ?? 'Site en maintenance';

            $startTime = date('Y-m-d H:i:s');
            $endTime = date('Y-m-d H:i:s', strtotime("+{$duration} minutes"));

            $maintenanceData = [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'message' => $message,
            ];

            file_put_contents($this->maintenanceFilePath, json_encode($maintenanceData));

            $io->success([
                'Maintenance mode enabled',
                "Duration: {$duration} minutes",
                "Start time: {$startTime}",
                "End time: {$endTime}",
                "Message: {$message}",
            ]);

            return Command::SUCCESS;
        }

        // Disable maintenance mode
        if ($input->getOption('disable')) {
            if (!file_exists($this->maintenanceFilePath)) {
                $io->error('Maintenance mode is already disabled');
                return Command::FAILURE;
            }

            unlink($this->maintenanceFilePath);
            $io->success('Maintenance mode disabled');
            return Command::SUCCESS;
        }

        // If no option is provided, show help
        $io->info('Please use one of the following options:');
        $io->listing([
            '--enable: Enable maintenance mode',
            '--disable: Disable maintenance mode',
            '--status: Show maintenance mode status',
            '--duration=X: Set maintenance duration in minutes (with --enable)',
            '--message="Custom message": Set custom maintenance message (with --enable)',
        ]);

        return Command::SUCCESS;
    }
}
