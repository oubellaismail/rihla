<?php

namespace App\Command;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Exception\BookingNotAllowedException;
use App\Exception\NotEnoughSeatsException;
use App\Service\BookingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Books seats from the command line. Mostly used by the concurrency test,
 * which starts several of these processes at once.
 */
#[AsCommand(name: 'app:booking:book', description: 'Book seats on a portion of a trip for a user')]
final class BookSeatCommand extends Command
{
    private const EXIT_NOT_FOUND = 1;
    private const EXIT_NOT_ALLOWED = 2;
    private const EXIT_NOT_ENOUGH_SEATS = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookingService $bookingService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tripId', InputArgument::REQUIRED, 'Trip id')
            ->addArgument('fromPosition', InputArgument::REQUIRED, 'Position of the boarding stop')
            ->addArgument('toPosition', InputArgument::REQUIRED, 'Position of the drop-off stop')
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the passenger')
            ->addOption('seats', null, InputOption::VALUE_REQUIRED, 'Number of seats', '1')
            ->addOption('hold-lock-ms', null, InputOption::VALUE_REQUIRED, 'Keep the trip lock this many milliseconds (concurrency tests)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $trip = $this->em->find(Trip::class, (int) $input->getArgument('tripId'));
        $passenger = $this->em->getRepository(User::class)->findOneBy(['email' => $input->getArgument('email')]);
        if (null === $trip || null === $passenger) {
            $io->error('Trip or user not found.');

            return self::EXIT_NOT_FOUND;
        }

        $from = $this->findStop($trip, (int) $input->getArgument('fromPosition'));
        $to = $this->findStop($trip, (int) $input->getArgument('toPosition'));
        if (null === $from || null === $to) {
            $io->error('Stop not found on this trip.');

            return self::EXIT_NOT_FOUND;
        }

        $holdMs = (int) $input->getOption('hold-lock-ms');
        $onLocked = $holdMs > 0 ? static function () use ($holdMs): void {
            usleep($holdMs * 1000);
        } : null;

        try {
            $booking = $this->bookingService->book($trip, $from, $to, (int) $input->getOption('seats'), $passenger, $onLocked);
        } catch (NotEnoughSeatsException $e) {
            $io->error($e->getMessage());

            return self::EXIT_NOT_ENOUGH_SEATS;
        } catch (BookingNotAllowedException $e) {
            $io->error($e->getMessage());

            return self::EXIT_NOT_ALLOWED;
        }

        $io->success(sprintf('Booking %d created.', $booking->getId()));

        return Command::SUCCESS;
    }

    private function findStop(Trip $trip, int $position): ?TripStop
    {
        foreach ($trip->getStops() as $stop) {
            if ($stop->getPosition() === $position) {
                return $stop;
            }
        }

        return null;
    }
}
