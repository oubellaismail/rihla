<?php

namespace App\Command;

use App\Service\TripCompletionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Completes finished trips and their bookings. Meant to run from a cron job, e.g. every 15 minutes.
 */
#[AsCommand(name: 'app:trips:complete', description: 'Mark finished trips and their bookings as completed')]
final class CompleteTripsCommand extends Command
{
    public function __construct(private readonly TripCompletionService $tripCompletion)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->success(sprintf('%d trip(s) completed.', $this->tripCompletion->completeFinishedTrips()));

        return Command::SUCCESS;
    }
}
