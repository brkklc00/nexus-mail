<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\EmailSmtpAccount;
use Doctrine\ORM\EntityManagerInterface;
use DateTime;

class EmailSmtpService
{
    private string $encryptionKey;

    public function __construct(
        private EntityManagerInterface $em
    ) {
        // .env'den encryption key al, yoksa varsayılan kullan
        $this->encryptionKey = $_ENV['APP_SECRET_KEY'] ?? 'nexus-mail-panel-secret-key-2024';
    }

    /**
     * Şifreyi encrypt et
     */
    public function encryptPassword(string $password): string
    {
        $iv = substr(hash('sha256', $this->encryptionKey), 0, 16);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
        
        if ($encrypted === false) {
            throw new \RuntimeException('Şifre şifrelenirken hata oluştu');
        }
        
        return base64_encode($encrypted);
    }

    /**
     * Şifreyi decrypt et
     */
    public function decryptPassword(string $encryptedPassword): string
    {
        $iv = substr(hash('sha256', $this->encryptionKey), 0, 16);
        $decoded = base64_decode($encryptedPassword);
        $decrypted = openssl_decrypt($decoded, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
        
        if ($decrypted === false) {
            throw new \RuntimeException('Şifre çözülürken hata oluştu');
        }
        
        return $decrypted;
    }

    /**
     * SMTP ile email gönder (custom subject ve body)
     */
    public function sendEmail(EmailSmtpAccount $smtp, string $to, string $subject, string $htmlBody): array
    {
        try {
            // Şifreyi decrypt et
            $password = $this->decryptPassword($smtp->getPassword());
            
            // SSL/TLS için stream context
            $streamContext = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);
            
            // Bağlantı tipini belirle
            $host = $smtp->getHost();
            $port = $smtp->getPort();
            $encryption = $smtp->getEncryption();
            
            // Port 2526: AUTH CRAM-MD5 kullan (encryption yok)
            $useAuthCramMd5 = ($port == 2526);
            
            // SSL (Port 465) için implicit SSL/TLS
            $useImplicitSSL = false;
            if ($encryption === 'ssl' || $port == 465) {
                $host = 'ssl://' . $host;
                $useImplicitSSL = true;
            }
            
            // Port 587 için ZORUNLU STARTTLS (encryption ayarına bakmadan!)
            // Modern SMTP sunucuları port 587'de STARTTLS olmadan AUTH LOGIN kabul etmez
            // Port 25 için sadece encryption 'tls' ise STARTTLS yap
            $requireSTARTTLS = ($port == 587 || ($port == 25 && $encryption === 'tls') || $encryption === 'tls');
            
            // SMTP sunucusuna bağlan
            $connection = @stream_socket_client(
                "{$host}:{$port}",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $streamContext
            );

            if (!$connection) {
                return [
                    'success' => false,
                    'message' => "SMTP sunucusuna bağlanılamadı: {$errstr} ({$errno})"
                ];
            }

            // SMTP yanıtını oku
            $response = fgets($connection, 515);
            
            if (strpos($response, '220') === false) {
                fclose($connection);
                return [
                    'success' => false,
                    'message' => "SMTP sunucusu beklenmeyen yanıt verdi: {$response}"
                ];
            }

            // EHLO komutu gönder
            fputs($connection, "EHLO localhost\r\n");
            $response = $this->getSmtpResponse($connection);
            
            // STARTTLS gerekiyorsa (TLS encryption veya port 587) VE port 2526 değilse
            if ($requireSTARTTLS && !$useImplicitSSL && !$useAuthCramMd5) {
                fputs($connection, "STARTTLS\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '220') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "STARTTLS başarısız: {$response}. Port 587 kullanıyorsanız Encryption'u TLS yapın."
                    ];
                }
                
                // TLS'e geç - Modern TLS versiyonları destekle (TLS 1.2, 1.3)
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT 
                              | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT 
                              | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                              
                $tlsResult = @stream_socket_enable_crypto($connection, true, $cryptoMethod);
                
                if (!$tlsResult) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "TLS encryption başlatılamadı. Modern TLS (1.2/1.3) gerekli. Encryption ayarını kontrol edin."
                    ];
                }
                
                // EHLO tekrar gönder (TLS şifrelemesi aktif olduktan sonra)
                fputs($connection, "EHLO localhost\r\n");
                $response = $this->getSmtpResponse($connection);
            }

            // Authentication - Port 2526 için AUTH CRAM-MD5
            if ($useAuthCramMd5) {
                // AUTH CRAM-MD5
                fputs($connection, "AUTH CRAM-MD5\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '334') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "AUTH CRAM-MD5 desteklenmiyor: {$response}"
                    ];
                }
                
                // Challenge'ı decode et
                $challenge = base64_decode(trim(substr($response, 4)));
                
                // HMAC-MD5 ile response oluştur
                $hmac = hash_hmac('md5', $challenge, $password);
                $cramResponse = $smtp->getUsername() . ' ' . $hmac;
                
                // Response gönder
                fputs($connection, base64_encode($cramResponse) . "\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '235') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "CRAM-MD5 kimlik doğrulama başarısız: {$response}"
                    ];
                }
            } else {
                // AUTH LOGIN (standart)
                fputs($connection, "AUTH LOGIN\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '334') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "AUTH LOGIN desteklenmiyor: {$response}"
                    ];
                }

                // Kullanıcı adı gönder
                fputs($connection, base64_encode($smtp->getUsername()) . "\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '334') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "Kullanıcı adı reddedildi: {$response}"
                    ];
                }

                // Şifre gönder
                fputs($connection, base64_encode($password) . "\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '235') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "Kimlik doğrulama başarısız: {$response}"
                    ];
                }
            }

            // MAIL FROM
            fputs($connection, "MAIL FROM: <{$smtp->getFromEmail()}>\r\n");
            $response = fgets($connection, 515);
            
            if (strpos($response, '250') === false) {
                fclose($connection);
                return [
                    'success' => false,
                    'message' => "MAIL FROM başarısız: {$response}"
                ];
            }

            // RCPT TO
            fputs($connection, "RCPT TO: <{$to}>\r\n");
            $response = fgets($connection, 515);
            
            if (strpos($response, '250') === false) {
                fclose($connection);
                return [
                    'success' => false,
                    'message' => "RCPT TO başarısız: {$response}"
                ];
            }

            // DATA
            fputs($connection, "DATA\r\n");
            $response = fgets($connection, 515);
            
            if (strpos($response, '354') === false) {
                fclose($connection);
                return [
                    'success' => false,
                    'message' => "DATA komutu başarısız: {$response}"
                ];
            }

            // Mail içeriği (custom subject ve body kullan)
            $date = date('r');
            $messageId = '<' . md5(uniqid()) . '@' . $smtp->getHost() . '>';
            
            $emailBody = "From: {$smtp->getFromName()} <{$smtp->getFromEmail()}>\r\n";
            $emailBody .= "To: <{$to}>\r\n";
            $emailBody .= "Subject: {$subject}\r\n";
            $emailBody .= "Date: {$date}\r\n";
            $emailBody .= "Message-ID: {$messageId}\r\n";
            $emailBody .= "MIME-Version: 1.0\r\n";
            $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $emailBody .= "Content-Transfer-Encoding: 8bit\r\n";
            $emailBody .= "\r\n";
            $emailBody .= $htmlBody . "\r\n";
            $emailBody .= ".\r\n";

            // Mail içeriğini gönder
            fputs($connection, $emailBody);
            $response = fgets($connection, 515);
            
            if (strpos($response, '250') === false) {
                fclose($connection);
                return [
                    'success' => false,
                    'message' => "Mail gönderilemedi: {$response}"
                ];
            }

            // QUIT
            fputs($connection, "QUIT\r\n");
            fclose($connection);

            return [
                'success' => true,
                'message' => 'Mail başarıyla gönderildi'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Mail gönderme hatası: ' . $e->getMessage()
            ];
        }
    }

    /**
     * SMTP bağlantısını test et
     */
    public function testSmtpConnection(EmailSmtpAccount $smtp, ?string $testEmail = null): array
    {
        try {
            // Şifreyi decrypt et
            $password = $this->decryptPassword($smtp->getPassword());
            
            // SSL/TLS için stream context
            $streamContext = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);
            
            // Bağlantı tipini belirle
            $host = $smtp->getHost();
            $port = $smtp->getPort();
            $encryption = $smtp->getEncryption();
            
            // Port 2526: HER ZAMAN AUTH CRAM-MD5 kullan (encryption ne olursa olsun)
            $useAuthCramMd5 = ($port == 2526);
            $useAuthPlain = false;
            
            // SSL (Port 465) için implicit SSL/TLS
            $useImplicitSSL = false;
            if ($encryption === 'ssl' || $port == 465) {
                $host = 'ssl://' . $host;
                $useImplicitSSL = true;
            }
            
            // Port 587 için ZORUNLU STARTTLS (encryption ayarına bakmadan!)
            // Modern SMTP sunucuları port 587'de STARTTLS olmadan AUTH LOGIN kabul etmez
            // Port 25 için sadece encryption 'tls' ise STARTTLS yap
            $requireSTARTTLS = ($port == 587 || ($port == 25 && $encryption === 'tls') || $encryption === 'tls');
            
            // SMTP sunucusuna bağlan
            $connection = @stream_socket_client(
                "{$host}:{$port}",
                $errno,
                $errstr,
                15, // Timeout artırıldı
                STREAM_CLIENT_CONNECT,
                $streamContext
            );

            if (!$connection) {
                return [
                    'success' => false,
                    'message' => "SMTP sunucusuna bağlanılamadı: {$errstr} ({$errno}). Host: {$host}, Port: {$port}"
                ];
            }

            // SMTP yanıtını oku
            $response = fgets($connection, 515);
            
            if (strpos($response, '220') === false) {
                fclose($connection);
                return [
                    'success' => false,
                    'message' => "SMTP sunucusu beklenmeyen yanıt verdi: {$response}"
                ];
            }

            // EHLO komutu gönder
            fputs($connection, "EHLO localhost\r\n");
            $response = $this->getSmtpResponse($connection);
            
            // STARTTLS gerekiyorsa (TLS encryption veya port 587) VE port 2526 değilse
            if ($requireSTARTTLS && !$useImplicitSSL && !$useAuthCramMd5) {
                fputs($connection, "STARTTLS\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '220') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "STARTTLS başarısız: {$response}. Port 587 kullanıyorsanız Encryption'u TLS yapın."
                    ];
                }
                
                // TLS'e geç - Modern TLS versiyonları destekle (TLS 1.2, 1.3)
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT 
                              | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT 
                              | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                              
                $tlsResult = @stream_socket_enable_crypto($connection, true, $cryptoMethod);
                
                if (!$tlsResult) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "TLS encryption başlatılamadı. Modern TLS (1.2/1.3) gerekli. Encryption ayarını kontrol edin."
                    ];
                }
                
                // EHLO tekrar gönder (TLS şifrelemesi aktif olduktan sonra)
                fputs($connection, "EHLO localhost\r\n");
                $response = $this->getSmtpResponse($connection);
            }

            // Authentication - Port 2526 için AUTH CRAM-MD5
            if ($useAuthCramMd5) {
                // AUTH CRAM-MD5
                fputs($connection, "AUTH CRAM-MD5\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '334') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "AUTH CRAM-MD5 desteklenmiyor: {$response}"
                    ];
                }
                
                // Challenge'ı decode et
                $challenge = base64_decode(trim(substr($response, 4)));
                
                // HMAC-MD5 ile response oluştur
                $hmac = hash_hmac('md5', $challenge, $password);
                $cramResponse = $smtp->getUsername() . ' ' . $hmac;
                
                // Response gönder
                fputs($connection, base64_encode($cramResponse) . "\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '235') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "CRAM-MD5 kimlik doğrulama başarısız: {$response}"
                    ];
                }
            } else {
                // AUTH LOGIN (standart)
                fputs($connection, "AUTH LOGIN\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '334') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "AUTH LOGIN desteklenmiyor: {$response}"
                    ];
                }

                // Kullanıcı adı gönder
                fputs($connection, base64_encode($smtp->getUsername()) . "\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '334') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "Kullanıcı adı reddedildi: {$response}"
                    ];
                }

                // Şifre gönder
                fputs($connection, base64_encode($password) . "\r\n");
                $response = fgets($connection, 515);
                
                if (strpos($response, '235') === false) {
                    fclose($connection);
                    return [
                        'success' => false,
                        'message' => "Kimlik doğrulama başarısız. Kullanıcı adı veya şifre hatalı. SMTP yanıtı: {$response}"
                    ];
                }
            }

            // Eğer test email adresi verilmişse, gerçek mail gönder
            if ($testEmail) {
                $result = $this->sendTestEmailViaSMTP($connection, $smtp, $testEmail);
                fclose($connection);
                return $result;
            }

            // QUIT
            fputs($connection, "QUIT\r\n");
            fclose($connection);

            return [
                'success' => true,
                'message' => 'SMTP bağlantısı ve kimlik doğrulama başarılı! ✓'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Bağlantı hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * SMTP yanıtını oku (çok satırlı olabilir)
     */
    private function getSmtpResponse($connection): string
    {
        $response = '';
        while ($line = fgets($connection, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] == ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * SMTP bağlantısı üzerinden gerçek test maili gönder
     */
    private function sendTestEmailViaSMTP($connection, EmailSmtpAccount $smtp, string $to): array
    {
        try {
            // MAIL FROM komutu
            fputs($connection, "MAIL FROM: <{$smtp->getFromEmail()}>\r\n");
            $response = fgets($connection, 515);
            
            if (strpos($response, '250') === false) {
                return [
                    'success' => false,
                    'message' => "MAIL FROM başarısız: {$response}"
                ];
            }

            // RCPT TO komutu
            fputs($connection, "RCPT TO: <{$to}>\r\n");
            $response = fgets($connection, 515);
            
            if (strpos($response, '250') === false) {
                return [
                    'success' => false,
                    'message' => "RCPT TO başarısız: {$response}"
                ];
            }

            // DATA komutu
            fputs($connection, "DATA\r\n");
            $response = fgets($connection, 515);
            
            if (strpos($response, '354') === false) {
                return [
                    'success' => false,
                    'message' => "DATA komutu başarısız: {$response}"
                ];
            }

            // Mail içeriği
            $subject = 'Nexus Panel - SMTP Test';
            $date = date('r');
            $messageId = '<' . md5(uniqid()) . '@' . $smtp->getHost() . '>';
            
            $emailBody = "From: {$smtp->getFromName()} <{$smtp->getFromEmail()}>\r\n";
            $emailBody .= "To: <{$to}>\r\n";
            $emailBody .= "Subject: {$subject}\r\n";
            $emailBody .= "Date: {$date}\r\n";
            $emailBody .= "Message-ID: {$messageId}\r\n";
            $emailBody .= "MIME-Version: 1.0\r\n";
            $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $emailBody .= "Content-Transfer-Encoding: 8bit\r\n";
            $emailBody .= "\r\n";
            $emailBody .= "<html><body style='font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f5f5f5;'>\r\n";
            $emailBody .= "<div style='max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>\r\n";
            $emailBody .= "  <div style='background-color: #0d6efd; padding: 30px; text-align: center;'>\r\n";
            $emailBody .= "    <h2 style='margin: 0; color: #ffffff; font-size: 24px;'>✓ SMTP Test Başarılı</h2>\r\n";
            $emailBody .= "  </div>\r\n";
            $emailBody .= "  <div style='padding: 30px; color: #333;'>\r\n";
            $emailBody .= "    <p style='margin: 0 0 20px 0; line-height: 1.6;'>Merhaba,</p>\r\n";
            $emailBody .= "    <p style='margin: 0 0 20px 0; line-height: 1.6;'>Bu bir test mesajıdır. SMTP bağlantınız başarıyla test edilmiştir.</p>\r\n";
            $emailBody .= "    <table style='width: 100%; margin: 20px 0; border-collapse: collapse;'>\r\n";
            $emailBody .= "      <tr><td style='padding: 8px 0; color: #666;'><strong>SMTP Hesabı:</strong></td><td style='padding: 8px 0;'>{$smtp->getName()}</td></tr>\r\n";
            $emailBody .= "      <tr style='background-color: #f8f9fa;'><td style='padding: 8px 0; color: #666;'><strong>Sunucu:</strong></td><td style='padding: 8px 0;'>{$smtp->getHost()}:{$smtp->getPort()}</td></tr>\r\n";
            $emailBody .= "      <tr><td style='padding: 8px 0; color: #666;'><strong>Gönderen:</strong></td><td style='padding: 8px 0;'>{$smtp->getFromEmail()}</td></tr>\r\n";
            $emailBody .= "      <tr style='background-color: #f8f9fa;'><td style='padding: 8px 0; color: #666;'><strong>Test Tarihi:</strong></td><td style='padding: 8px 0;'>" . date('d.m.Y H:i:s') . "</td></tr>\r\n";
            $emailBody .= "    </table>\r\n";
            $emailBody .= "    <p style='margin: 20px 0 0 0; line-height: 1.6; color: #666;'>Bu mail otomatik olarak gönderilmiştir.</p>\r\n";
            $emailBody .= "  </div>\r\n";
            $emailBody .= "  <div style='padding: 20px; text-align: center; background-color: #f8f9fa; border-top: 1px solid #e9ecef;'>\r\n";
            $emailBody .= "    <p style='margin: 0; color: #666; font-size: 13px;'>Nexus Panel - Email Yönetim Sistemi</p>\r\n";
            $emailBody .= "  </div>\r\n";
            $emailBody .= "</div>\r\n";
            $emailBody .= "</body></html>\r\n";
            $emailBody .= ".\r\n";

            // Mail içeriğini gönder
            fputs($connection, $emailBody);
            $response = fgets($connection, 515);
            
            if (strpos($response, '250') === false) {
                return [
                    'success' => false,
                    'message' => "Mail gönderilemedi: {$response}"
                ];
            }

            // QUIT
            fputs($connection, "QUIT\r\n");

            return [
                'success' => true,
                'message' => "✓ Test maili başarıyla gönderildi! '{$to}' adresini kontrol edin."
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Mail gönderim hatası: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Günlük limitleri sıfırla (Cron job ile çağrılmalı)
     */
    public function resetDailyLimits(): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('s')
            ->from(EmailSmtpAccount::class, 's')
            ->where('s.isActive = :active')
            ->setParameter('active', true);

        $smtps = $qb->getQuery()->getResult();
        $resetCount = 0;

        foreach ($smtps as $smtp) {
            if ($smtp->shouldResetToday()) {
                $smtp->resetDailySent();
                $this->em->persist($smtp);
                $resetCount++;
            }
        }

        $this->em->flush();

        return $resetCount;
    }

    /**
     * Tüm SMTP limitlerini manuel olarak sıfırla (günlük, saatlik, dakikalık)
     */
    public function forceResetAllLimits(): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('s')
            ->from(EmailSmtpAccount::class, 's');

        $smtps = $qb->getQuery()->getResult();
        $resetCount = 0;

        foreach ($smtps as $smtp) {
            // Tüm limitleri zorla sıfırla
            $smtp->resetDailySent();
            $smtp->resetHourlySent();
            $smtp->resetMinuteSent();
            $this->em->persist($smtp);
            $resetCount++;
        }

        $this->em->flush();

        return $resetCount;
    }

    /**
     * SMTP istatistiklerini al
     */
    public function getStats(): array
    {
        // Toplam SMTP
        $qb1 = $this->em->createQueryBuilder();
        $totalSmtp = $qb1->select('COUNT(s.id)')
            ->from(EmailSmtpAccount::class, 's')
            ->getQuery()
            ->getSingleScalarResult();

        // Aktif SMTP
        $qb2 = $this->em->createQueryBuilder();
        $activeSmtp = $qb2->select('COUNT(s.id)')
            ->from(EmailSmtpAccount::class, 's')
            ->where('s.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        // Bugün gönderilen
        $qb3 = $this->em->createQueryBuilder();
        $todaySent = $qb3->select('SUM(s.dailySent)')
            ->from(EmailSmtpAccount::class, 's')
            ->where('s.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Toplam gönderilen (tüm zamanlar)
        $qb5 = $this->em->createQueryBuilder();
        $totalSent = $qb5->select('SUM(s.totalSent)')
            ->from(EmailSmtpAccount::class, 's')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Ortalama başarı oranı
        $qb4 = $this->em->createQueryBuilder();
        $avgSuccessRate = $qb4->select('AVG(s.successRate)')
            ->from(EmailSmtpAccount::class, 's')
            ->where('s.isActive = :active')
            ->andWhere('s.totalSent > 0')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult() ?? 100;

        $allAccounts = $this->em->getRepository(EmailSmtpAccount::class)->findAll();
        $healthySmtp = 0;
        $warmingSmtp = 0;
        $riskSmtp = 0;
        $passiveSmtp = 0;

        foreach ($allAccounts as $smtp) {
            if (!$smtp instanceof EmailSmtpAccount) {
                continue;
            }

            $isActive = $smtp->isActive();
            $isWarm = $isActive && $smtp->getTotalSent() < 100;
            $hasConnectionError = trim((string) ($smtp->getLastError() ?? '')) !== '';
            $isRisk = $isActive && (
                $hasConnectionError
                || ($smtp->getTotalSent() >= 15 && $smtp->getSuccessRate() < 85.0)
            );
            $isHealthy = $isActive && !$isWarm && !$isRisk && !$hasConnectionError && $smtp->getSuccessRate() >= 85.0;

            if (!$isActive) {
                ++$passiveSmtp;
                continue;
            }

            if ($isWarm) {
                ++$warmingSmtp;
                continue;
            }

            if ($isRisk) {
                ++$riskSmtp;
                continue;
            }

            if ($isHealthy) {
                ++$healthySmtp;
            }
        }

        return [
            'total_smtp' => (int) $totalSmtp,
            'active_smtp' => (int) $activeSmtp,
            'passive_smtp' => (int) $passiveSmtp,
            'healthy_smtp' => (int) $healthySmtp,
            'warming_smtp' => (int) $warmingSmtp,
            'risk_smtp' => (int) $riskSmtp,
            'today_sent' => (int) $todaySent,
            'total_sent' => (int) $totalSent,
            'avg_success_rate' => round((float) $avgSuccessRate, 2)
        ];
    }

    /**
     * Kullanım kaydı oluştur
     */
    public function recordUsage(EmailSmtpAccount $smtp, bool $success, ?string $error = null): void
    {
        $smtp->incrementDailySent();
        $smtp->setLastUsedAt(new DateTime());

        if ($success) {
            $smtp->incrementTotalSent();
        } else {
            $smtp->incrementTotalFailed();
            if ($error) {
                $smtp->setLastError($error);
            }
        }

        $smtp->calculateSuccessRate();
        
        $this->em->persist($smtp);
        $this->em->flush();
    }
}

