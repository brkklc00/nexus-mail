<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\EmailPhonebook;
use App\Domain\Entities\EmailContact;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailPhoneBookController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig
    ) {
    }

    /**
     * Rehber listesi
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);

        $qb = $this->em->createQueryBuilder();
        $phonebooks = $qb->select('p')
            ->from(EmailPhonebook::class, 'p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $html = $this->twig->render('email-phonebook/index.twig', [
            'phonebooks' => $phonebooks,
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null
        ]);
        
        // Mesajları temizle
        unset($_SESSION['success'], $_SESSION['error']);
        
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Rehber kaydetme (Yeni rehber oluştur veya mevcut rehbere ekle)
     */
    public function store(Request $request, Response $response): Response
    {
        // ULTRA PERFORMANS: 10M mail için limitler
        @ini_set('memory_limit', '1G');
        @ini_set('max_execution_time', '600');
        @ini_set('max_input_time', '600');
        
        $startTime = microtime(true);
        
        $data = $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $operationType = $data['operation_type'] ?? 'create';

        try {
            $emailList = [];
            
            // Client-side parse edilmiş mailler (textarea'dan)
            if (!empty($data['emails'])) {
                $emailList = $this->parseEmails($data['emails']);
                $emailList = $this->uniqueEmails($emailList);
            }
            
            // Eğer email listesi boşsa hata
            if (empty($emailList) && $operationType === 'create') {
                // Yeni rehber oluştururken mail adresi olmadan da izin ver
            } elseif (empty($emailList) && $operationType === 'add') {
                $_SESSION['error'] = 'Mail adresleri girilmedi';
                return $response
                    ->withHeader('Location', '/email-phonebooks')
                    ->withStatus(302);
            }
            
            if ($operationType === 'create') {
                // Yeni rehber oluştur
                $phonebook = new EmailPhonebook();
                $phonebook->setUser($user);
                $phonebook->setTitle($data['title']);
                $phonebook->setDescription($data['description'] ?? null);

                $this->em->persist($phonebook);
                $this->em->flush();
                
                $phonebookId = $phonebook->getId();
                $totalEmails = count($emailList);
                
                if ($totalEmails > 0) {
                    $this->bulkInsertEmails($phonebookId, $emailList);
                }
                
                $phonebook->setTotalContacts($totalEmails);
                $this->em->flush();
                $this->em->clear();
                
                $totalTime = microtime(true) - $startTime;

                $_SESSION['success'] = "Rehber başarıyla oluşturuldu! " . number_format($totalEmails) . " mail adresi " . round($totalTime, 1) . " saniyede eklendi.";
                
            } elseif ($operationType === 'add') {
                // Mevcut rehbere ekle
                $phonebookId = (int) ($data['existing_phonebook_id'] ?? 0);
                $phonebook = $this->em->find(EmailPhonebook::class, $phonebookId);

                if (!$phonebook || $phonebook->getUser()->getId() !== $user->getId()) {
                    $_SESSION['error'] = 'Rehber bulunamadı';
                    return $response
                        ->withHeader('Location', '/email-phonebooks')
                        ->withStatus(302);
                }

                // ULTRA HIZLI: Mevcut mail adreslerini Set'e al
                $conn = $this->em->getConnection();
                $existingEmailsQuery = 'SELECT email FROM email_contacts WHERE phonebook_id = ?';
                $stmt = $conn->prepare($existingEmailsQuery);
                $stmt->bindValue(1, $phonebook->getId());
                $result = $stmt->executeQuery();
                $existingEmails = $result->fetchFirstColumn();
                
                $existingEmailSet = array_flip(array_map('strtolower', $existingEmails));
                
                // Yeni mailleri filtrele
                $newEmails = [];
                foreach ($emailList as $emailData) {
                    $emailLower = strtolower($emailData['email']);
                    if (!isset($existingEmailSet[$emailLower])) {
                        $newEmails[] = $emailData;
                        $existingEmailSet[$emailLower] = true;
                    }
                }
                
                $addedCount = count($newEmails);
                
                if ($addedCount > 0) {
                    $this->bulkInsertEmails($phonebook->getId(), $newEmails);
                }
                
                // Toplam sayıyı güncelle
                $phonebook->setTotalContacts($phonebook->getTotalContacts() + $addedCount);
                $this->em->flush();
                $this->em->clear();

                $_SESSION['success'] = "$addedCount mail adresi rehbere eklendi";
            }

            return $response
                ->withHeader('Location', '/email-phonebooks')
                ->withStatus(302);

        } catch (\Exception $e) {
            // Detaylı hata loglama
            
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
            
            return $response
                ->withHeader('Location', '/email-phonebooks')
                ->withStatus(302);
        }
    }

    /**
     * /email-phonebooks/{id} linklerini detay sayfasına yönlendir
     */
    public function redirectToDetail(Request $request, Response $response, array $args): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $phonebook = $this->em->find(EmailPhonebook::class, (int) $args['id']);

        if (!$phonebook || $phonebook->getUser()->getId() !== $user->getId()) {
            $_SESSION['error'] = 'Rehber bulunamadı';
            return $response
                ->withHeader('Location', '/email-phonebooks')
                ->withStatus(302);
        }

        return $response
            ->withHeader('Location', '/email-phonebooks/' . $args['id'] . '/detail')
            ->withStatus(302);
    }

    /**
     * Rehber detayı
     */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $phonebook = $this->em->find(EmailPhonebook::class, (int) $args['id']);

        if (!$phonebook || $phonebook->getUser()->getId() !== $user->getId()) {
            $response->getBody()->write(json_encode(['error' => 'Rehber bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Sayfalama
        $page = (int) ($request->getQueryParams()['page'] ?? 1);
        $perPage = 100; // Optimizasyon için artırıldı
        
        // Toplam sayı
        $total = $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(EmailContact::class, 'c')
            ->where('c.phonebook = :phonebook')
            ->setParameter('phonebook', $phonebook)
            ->getQuery()
            ->getSingleScalarResult();

        // Kontaklar
        $contacts = $this->em->createQueryBuilder()
            ->select('c')
            ->from(EmailContact::class, 'c')
            ->where('c.phonebook = :phonebook')
            ->setParameter('phonebook', $phonebook)
            ->orderBy('c.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $totalPages = ceil($total / $perPage);

        $html = $this->twig->render('email-phonebook/_detail.twig', [
            'phonebook' => $phonebook,
            'contacts' => $contacts,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
            'per_page' => $perPage
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Rehber silme (ULTRA HIZLI)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $phonebook = $this->em->find(EmailPhonebook::class, (int) $args['id']);

        if (!$phonebook || $phonebook->getUser()->getId() !== $user->getId()) {
            $_SESSION['error'] = 'Rehber bulunamadı';
            return $response
                ->withHeader('Location', '/email-phonebooks')
                ->withStatus(302);
        }

        try {
            $phonebookId = $phonebook->getId();
            $totalContacts = $phonebook->getTotalContacts();
            
            // ULTRA HIZLI: Raw SQL ile toplu silme
            $conn = $this->em->getConnection();
            
            // Önce kontakları sil
            $deleteContactsSql = 'DELETE FROM email_contacts WHERE phonebook_id = ?';
            $stmt = $conn->prepare($deleteContactsSql);
            $stmt->bindValue(1, $phonebookId);
            $deletedContacts = $stmt->executeStatement();
            
            // Sonra rehberi sil
            $deletePhonebookSql = 'DELETE FROM email_phonebooks WHERE id = ?';
            $stmt2 = $conn->prepare($deletePhonebookSql);
            $stmt2->bindValue(1, $phonebookId);
            $stmt2->executeStatement();
            
            // Entity manager'ı temizle
            $this->em->clear();

            $_SESSION['success'] = "Rehber başarıyla silindi! " . number_format($totalContacts) . " mail adresi kaldırıldı.";

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Rehber silinirken hata: ' . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/email-phonebooks')
            ->withStatus(302);
    }

    /**
     * Email listesini parse et (ULTRA HIZLI - Minimal Validation)
     */
    private function parseEmails(string $emailsText): array
    {
        // HYPER FAST parsing - preg_split + regex
        $lines = preg_split('/[\r\n]+/', $emailsText, -1, PREG_SPLIT_NO_EMPTY);
        $emails = [];
        $emailPattern = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

        foreach ($lines as $line) {
            $line = strtolower(trim($line));
            
            if (strlen($line) < 5) continue;

            // Email,Name formatı
            if (strpos($line, ',') !== false) {
                $parts = explode(',', $line, 2);
                $email = trim($parts[0]);
                $name = trim($parts[1] ?? '');

                if (preg_match($emailPattern, $email)) {
                    $emails[] = ['email' => $email, 'name' => $name ?: null];
                }
            }
            // Sadece email
            elseif (preg_match($emailPattern, $line)) {
                $emails[] = ['email' => $line, 'name' => null];
            }
        }

        return $emails;
    }

    /**
     * Yüklenen dosyayı işle ve email listesi döndür
     */
    private function processUploadedFile($uploadedFile): array
    {
        $emails = [];
        $filename = $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Geçici dosya yolu
        $tmpFile = tempnam(sys_get_temp_dir(), 'email_upload_');
        $uploadedFile->moveTo($tmpFile);
        
        try {
            if ($extension === 'txt') {
                // TXT dosyası - her satırda bir mail
                $content = file_get_contents($tmpFile);
                $emails = $this->parseEmails($content);
                
            } elseif ($extension === 'csv') {
                // CSV dosyası
                $handle = fopen($tmpFile, 'r');
                while (($row = fgetcsv($handle)) !== false) {
                    if (!empty($row[0]) && filter_var(trim($row[0]), FILTER_VALIDATE_EMAIL)) {
                        $emails[] = [
                            'email' => trim($row[0]),
                            'name' => isset($row[1]) ? trim($row[1]) : null
                        ];
                    }
                }
                fclose($handle);
                
            } elseif (in_array($extension, ['xls', 'xlsx'])) {
                // Excel dosyası - PhpSpreadsheet kullanarak
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile);
                $worksheet = $spreadsheet->getActiveSheet();
                
                foreach ($worksheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    
                    $cells = [];
                    foreach ($cellIterator as $cell) {
                        $cells[] = $cell->getValue();
                    }
                    
                    if (!empty($cells[0]) && filter_var(trim($cells[0]), FILTER_VALIDATE_EMAIL)) {
                        $emails[] = [
                            'email' => trim($cells[0]),
                            'name' => isset($cells[1]) ? trim($cells[1]) : null
                        ];
                    }
                }
            }
            
        } finally {
            // Geçici dosyayı sil
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
        
        return $emails;
    }

    /**
     * Email listesini benzersiz yap (ULTRA HIZLI)
     */
    private function uniqueEmails(array $emails): array
    {
        $unique = [];
        $seen = []; // Set olarak kullan (array_key_exists = O(1))
        
        foreach ($emails as $emailData) {
            $email = strtolower($emailData['email']);
            // isset() O(1) - in_array() O(n)'den çok daha hızlı
            if (!isset($seen[$email])) {
                $seen[$email] = true;
                $unique[] = $emailData;
            }
        }
        
        return $unique;
    }

    /**
     * Rehber indirme (CSV, Excel, TXT)
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $phonebook = $this->em->find(EmailPhonebook::class, (int) $args['id']);

        if (!$phonebook || $phonebook->getUser()->getId() !== $user->getId()) {
            $_SESSION['error'] = 'Rehber bulunamadı';
            return $response
                ->withHeader('Location', '/email-phonebooks')
                ->withStatus(302);
        }

        $format = $request->getQueryParams()['format'] ?? 'csv';
        
        try {
            // Tüm mail adreslerini çek
            $contacts = $this->em->createQueryBuilder()
                ->select('c')
                ->from(EmailContact::class, 'c')
                ->where('c.phonebook = :phonebook')
                ->setParameter('phonebook', $phonebook)
                ->orderBy('c.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

            if ($format === 'txt') {
                // TXT formatı - sadece mail adresleri
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
                // Excel formatı
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                
                // Başlıklar
                $sheet->setCellValue('A1', 'Email');
                $sheet->setCellValue('B1', 'İsim');
                $sheet->setCellValue('C1', 'Eklenme Tarihi');
                
                // Header style
                $headerStyle = [
                    'font' => ['bold' => true, 'size' => 12],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4CAF50']],
                    'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
                ];
                $sheet->getStyle('A1:C1')->applyFromArray($headerStyle);
                
                // Data
                $row = 2;
                foreach ($contacts as $contact) {
                    $sheet->setCellValue('A' . $row, $contact->getEmail());
                    $sheet->setCellValue('B' . $row, $contact->getName() ?? '');
                    $sheet->setCellValue('C' . $row, $contact->getCreatedAt()->format('d.m.Y H:i'));
                    $row++;
                }
                
                // Auto width
                $sheet->getColumnDimension('A')->setAutoSize(true);
                $sheet->getColumnDimension('B')->setAutoSize(true);
                $sheet->getColumnDimension('C')->setAutoSize(true);
                
                $filename = $this->sanitizeFilename($phonebook->getTitle()) . '_' . date('Y-m-d') . '.xlsx';
                
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                
                // Write to output
                ob_start();
                $writer->save('php://output');
                $content = ob_get_clean();
                
                $response->getBody()->write($content);
                return $response
                    ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                    ->withHeader('Cache-Control', 'max-age=0');
                    
            } else {
                // CSV formatı (default)
                $filename = $this->sanitizeFilename($phonebook->getTitle()) . '_' . date('Y-m-d') . '.csv';
                
                // CSV oluştur
                $output = fopen('php://temp', 'r+');
                
                // BOM ekle (UTF-8 için Excel uyumluluğu)
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
                
                // Başlıklar
                fputcsv($output, ['Email', 'İsim', 'Eklenme Tarihi']);
                
                // Data
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
                ->withHeader('Location', '/email-phonebooks')
                ->withStatus(302);
        }
    }

    /**
     * Dosya adını temizle
     */
    private function sanitizeFilename(string $filename): string
    {
        // Türkçe karakterleri dönüştür
        $turkish = ['ş', 'Ş', 'ı', 'İ', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'];
        $english = ['s', 'S', 'i', 'I', 'g', 'G', 'u', 'U', 'o', 'O', 'c', 'C'];
        $filename = str_replace($turkish, $english, $filename);
        
        // Özel karakterleri kaldır
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        
        // Birden fazla alt çizgiyi tek alt çizgiye dönüştür
        $filename = preg_replace('/_+/', '_', $filename);
        
        // Baştaki ve sondaki alt çizgileri kaldır
        $filename = trim($filename, '_');
        
        return $filename ?: 'rehber';
    }

    /**
     * ULTRA HIZLI: Bulk insert emails using raw SQL
     */
    private function bulkInsertEmails(int $phonebookId, array $emailList): void
    {
        if (empty($emailList)) {
            return;
        }

        $conn = $this->em->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        
        // HYPER SPEED: Transaction + Büyük batch'ler
        $conn->beginTransaction();
        
        try {
            $batchSize = 10000; // Daha büyük batch (2x daha hızlı)
            
            foreach (array_chunk($emailList, $batchSize) as $batchEmails) {
                $values = [];
                $params = [];
                
                foreach ($batchEmails as $emailData) {
                    $values[] = "(?, ?, ?, ?)";
                    $params[] = $phonebookId;
                    $params[] = $emailData['email'];
                    $params[] = $emailData['name'] ?? null;
                    $params[] = $now;
                }
                
                // INSERT IGNORE - Duplicate'lar otomatik skip (çok hızlı!)
                $sql = "INSERT IGNORE INTO email_contacts (phonebook_id, email, name, created_at) VALUES " 
                     . implode(', ', $values);
                
                $conn->executeStatement($sql, $params);
            }
            
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
}

