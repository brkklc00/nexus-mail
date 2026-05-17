<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\Entities\EmailContact;
use App\Domain\Entities\EmailPhonebook;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailPhonebookController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig
    ) {
    }

    /**
     * Tüm email rehberlerini listele
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        $userId = $params['user_id'] ?? '';
        $page = (int) ($params['page'] ?? 1);
        $perPage = 50;

        $qb = $this->em->createQueryBuilder();
        $qb->select('p', 'u')
            ->from(EmailPhonebook::class, 'p')
            ->leftJoin('p.user', 'u')
            ->orderBy('p.createdAt', 'DESC');

        // Arama
        if ($search) {
            $qb->andWhere('p.title LIKE :search OR u.name LIKE :search OR u.email LIKE :search')
                ->setParameter('search', "%{$search}%");
        }

        // User filtresi
        if ($userId) {
            $qb->andWhere('p.user = :userId')
                ->setParameter('userId', $userId);
        }

        // Pagination
        $total = count($qb->getQuery()->getResult());
        $totalPages = ceil($total / $perPage);
        
        $phonebooks = $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        // İstatistikler
        $stats = $this->getStats();

        // Kullanıcı listesi
        $users = $this->em->createQueryBuilder()
            ->select('u.id', 'u.name', 'u.email')
            ->from(User::class, 'u')
            ->where('u.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        $html = $this->twig->render('admin/email-phonebooks/index.twig', [
            'phonebooks' => $phonebooks,
            'stats' => $stats,
            'users' => $users,
            'search' => $search,
            'selected_user' => $userId,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * İstatistikler
     */
    private function getStats(): array
    {
        // Toplam rehber
        $qb1 = $this->em->createQueryBuilder();
        $total = $qb1->select('COUNT(p.id)')
            ->from(EmailPhonebook::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        // Toplam email
        $qb2 = $this->em->createQueryBuilder();
        $totalEmails = $qb2->select('SUM(p.totalContacts)')
            ->from(EmailPhonebook::class, 'p')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Aktif kullanıcı sayısı
        $qb3 = $this->em->createQueryBuilder();
        $activeUsers = $qb3->select('COUNT(DISTINCT p.user)')
            ->from(EmailPhonebook::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => (int) $total,
            'total_emails' => (int) $totalEmails,
            'active_users' => (int) $activeUsers
        ];
    }

    /**
     * Rehber indirme (CSV, Excel, TXT) - Admin tüm rehberleri indirebilir
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $phonebook = $this->em->find(EmailPhonebook::class, (int) $args['id']);

        if (!$phonebook) {
            $_SESSION['error'] = 'Rehber bulunamadı';
            return $response
                ->withHeader('Location', '/admin/email-phonebooks')
                ->withStatus(302);
        }

        $format = $request->getQueryParams()['format'] ?? 'csv';

        try {
            $contacts = $this->em->createQueryBuilder()
                ->select('c')
                ->from(EmailContact::class, 'c')
                ->where('c.phonebook = :phonebook')
                ->setParameter('phonebook', $phonebook)
                ->orderBy('c.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

            if ($format === 'txt') {
                $content = '';
                foreach ($contacts as $contact) {
                    $content .= $contact->getEmail() . "\n";
                }
                $filename = $this->sanitizeFilename($phonebook->getTitle()) . '_' . date('Y-m-d') . '.txt';
                $response->getBody()->write($content);
                return $response
                    ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                    ->withHeader('Cache-Control', 'max-age=0');

            } elseif ($format === 'excel') {
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setCellValue('A1', 'Email');
                $sheet->setCellValue('B1', 'İsim');
                $sheet->setCellValue('C1', 'Eklenme Tarihi');
                $headerStyle = [
                    'font' => ['bold' => true, 'size' => 12],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4CAF50']],
                    'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
                ];
                $sheet->getStyle('A1:C1')->applyFromArray($headerStyle);
                $row = 2;
                foreach ($contacts as $contact) {
                    $sheet->setCellValue('A' . $row, $contact->getEmail());
                    $sheet->setCellValue('B' . $row, $contact->getName() ?? '');
                    $sheet->setCellValue('C' . $row, $contact->getCreatedAt()->format('d.m.Y H:i'));
                    $row++;
                }
                $sheet->getColumnDimension('A')->setAutoSize(true);
                $sheet->getColumnDimension('B')->setAutoSize(true);
                $sheet->getColumnDimension('C')->setAutoSize(true);
                $filename = $this->sanitizeFilename($phonebook->getTitle()) . '_' . date('Y-m-d') . '.xlsx';
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                ob_start();
                $writer->save('php://output');
                $content = ob_get_clean();
                $response->getBody()->write($content);
                return $response
                    ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                    ->withHeader('Cache-Control', 'max-age=0');

            } else {
                $filename = $this->sanitizeFilename($phonebook->getTitle()) . '_' . date('Y-m-d') . '.csv';
                $output = fopen('php://temp', 'r+');
                fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($output, ['Email', 'İsim', 'Eklenme Tarihi']);
                foreach ($contacts as $contact) {
                    fputcsv($output, [
                        $contact->getEmail(),
                        $contact->getName() ?? '',
                        $contact->getCreatedAt()->format('d.m.Y H:i')
                    ]);
                }
                rewind($output);
                $content = stream_get_contents($output);
                fclose($output);
                $response->getBody()->write($content);
                return $response
                    ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                    ->withHeader('Cache-Control', 'max-age=0');
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Rehber indirilirken hata: ' . $e->getMessage();
            return $response
                ->withHeader('Location', '/admin/email-phonebooks')
                ->withStatus(302);
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $turkish = ['ş', 'Ş', 'ı', 'İ', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'];
        $english = ['s', 'S', 'i', 'I', 'g', 'G', 'u', 'U', 'o', 'O', 'c', 'C'];
        $filename = str_replace($turkish, $english, $filename);
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        $filename = trim($filename, '_');
        return $filename ?: 'rehber';
    }
}

