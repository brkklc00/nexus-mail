<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Domain\Entities\User;
use App\Infrastructure\Security\PasswordHasher;
use Doctrine\ORM\EntityManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\Question;

class ResetPasswordCommand extends Command
{
    protected static $defaultName = 'user:reset-password';
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Reset a user password')
            ->addArgument('username', InputArgument::OPTIONAL, 'Username or email')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'New password')
            ->addOption('admin', 'a', InputOption::VALUE_NONE, 'Reset admin user password')
            ->setHelp('This command allows you to reset a user password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            /** @var EntityManager $em */
            $em = $this->container->get(EntityManager::class);
            $passwordHasher = $this->container->get(PasswordHasher::class);

            // Kullanıcı belirleme
            $username = $input->getArgument('username');
            $isAdmin = $input->getOption('admin');

            if ($isAdmin) {
                $username = 'admin';
            }

            // Kullanıcı adı yoksa sor
            if (!$username) {
                $question = new Question('Kullanıcı adı veya email: ');
                $username = $io->askQuestion($question);
            }

            if (empty($username)) {
                $io->error('Kullanıcı adı veya email gerekli!');
                return Command::FAILURE;
            }

            // Kullanıcıyı bul
            $userRepo = $em->getRepository(User::class);
            $user = $userRepo->findOneBy(['username' => $username]);
            
            if (!$user) {
                $user = $userRepo->findOneBy(['email' => $username]);
            }

            if (!$user) {
                $io->error("Kullanıcı bulunamadı: {$username}");
                return Command::FAILURE;
            }

            $io->title("Şifre Sıfırlama - {$user->getName()} ({$user->getUsername()})");

            // Yeni şifre belirleme
            $newPassword = $input->getOption('password');

            if (!$newPassword) {
                $question = new Question('Yeni şifre: ');
                $question->setHidden(true);
                $question->setHiddenFallback(false);
                $newPassword = $io->askQuestion($question);

                $confirmQuestion = new Question('Şifreyi tekrar girin: ');
                $confirmQuestion->setHidden(true);
                $confirmQuestion->setHiddenFallback(false);
                $confirmPassword = $io->askQuestion($confirmQuestion);

                if ($newPassword !== $confirmPassword) {
                    $io->error('Şifreler eşleşmiyor!');
                    return Command::FAILURE;
                }
            }

            if (empty($newPassword)) {
                $io->error('Şifre boş olamaz!');
                return Command::FAILURE;
            }

            if (strlen($newPassword) < 6) {
                $io->error('Şifre en az 6 karakter olmalıdır!');
                return Command::FAILURE;
            }

            // Şifreyi güncelle
            $hashedPassword = $passwordHasher->hash($newPassword);
            $user->setPassword($hashedPassword);
            
            $em->flush();

            $io->success('Şifre başarıyla güncellendi!');
            
            $io->table(
                ['Alan', 'Değer'],
                [
                    ['Kullanıcı', $user->getName()],
                    ['Username', $user->getUsername()],
                    ['Email', $user->getEmail()],
                    ['Yeni Şifre', str_repeat('*', strlen($newPassword))],
                ]
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Şifre sıfırlama başarısız: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

