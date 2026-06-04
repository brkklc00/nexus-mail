<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Domain\Entities\EmailTransaction;
use App\Domain\Entities\User;
use App\Domain\Enum\EmailTransactionType;
use Doctrine\ORM\EntityManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SetAdminEmailCreditCommand extends Command
{
    private const DEFAULT_AMOUNT = 100_000_000.0;

    protected static $defaultName = 'user:set-admin-email-credit';

    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Admin kullanıcısının mail kredisini belirli bir değere ayarlar')
            ->addOption('username', 'u', InputOption::VALUE_OPTIONAL, 'Hedef kullanıcı adı', 'admin')
            ->addOption('amount', 'a', InputOption::VALUE_OPTIONAL, 'Yeni mail kredi bakiyesi', (string) self::DEFAULT_AMOUNT)
            ->setHelp('Varsayılan: admin kullanıcısına 100.000.000 mail kredisi. Önce migrations:migrate çalıştırın (DECIMAL genişletme).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            /** @var EntityManager $em */
            $em = $this->container->get(EntityManager::class);

            $username = (string) $input->getOption('username');
            $amount = (float) $input->getOption('amount');

            if ($amount < 0) {
                $io->error('Miktar negatif olamaz.');
                return Command::FAILURE;
            }

            $user = $em->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                $io->error("Kullanıcı bulunamadı: {$username}");
                return Command::FAILURE;
            }

            $balanceBefore = $user->getEmailCredit();
            $delta = $amount - $balanceBefore;

            $user->setEmailCredit($amount);

            if (abs($delta) > 0.0001) {
                $transaction = new EmailTransaction();
                $transaction->setUser($user);
                $transaction->setType($delta >= 0 ? EmailTransactionType::CREDIT : EmailTransactionType::DEBIT);
                $transaction->setAmount(abs($delta));
                $transaction->setDescription('Konsol: admin mail kredi ayarı (user:set-admin-email-credit)');
                $transaction->setBalanceBefore($balanceBefore);
                $transaction->setBalanceAfter($amount);
                $em->persist($transaction);
            }

            $em->flush();

            $io->success(sprintf(
                '%s kullanıcısının mail kredisi %s olarak ayarlandı (önceki: %s).',
                $user->getUsername(),
                number_format($amount, 0, ',', '.'),
                number_format($balanceBefore, 0, ',', '.')
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('İşlem başarısız: ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'Out of range') || str_contains($e->getMessage(), '1264')) {
                $io->note('Önce şunu çalıştırın: php bin/console migrations:migrate');
            }
            return Command::FAILURE;
        }
    }
}
