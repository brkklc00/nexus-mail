<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\ExchangeRate;
use Doctrine\ORM\EntityManager;
use DateTimeImmutable;
use RuntimeException;

class ExchangeRateService
{
    private EntityManager $em;
    
    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }
    
    /**
     * Döviz kurunu getir (veritabanından veya API'den)
     */
    public function getRate(string $from, string $to): float
    {
        // Aynı para birimi ise 1 döndür
        if ($from === $to) {
            return 1.0;
        }
        
        // Veritabanından kuru kontrol et
        $exchangeRate = $this->em->getRepository(ExchangeRate::class)
            ->findOneBy([
                'fromCurrency' => $from,
                'toCurrency' => $to,
            ]);
        
        // Eğer kur varsa ve 1 saatten yeni ise kullan
        if ($exchangeRate) {
            $updatedAt = $exchangeRate->getUpdatedAt() ?? $exchangeRate->getCreatedAt();
            $diff = (new DateTimeImmutable())->getTimestamp() - $updatedAt->getTimestamp();
            
            if ($diff < 3600) { // 1 saat
                return (float) $exchangeRate->getRate();
            }
        }
        
        // API'den güncelle
        return $this->updateRate($from, $to);
    }
    
    /**
     * Döviz kurunu API'den güncelle
     */
    public function updateRate(string $from, string $to): float
    {
        try {
            // TCMB API'si veya başka bir kaynak kullanılabilir
            // Örnek olarak exchangerate-api.com kullanıyoruz (ücretsiz)
            $apiUrl = "https://api.exchangerate-api.com/v4/latest/{$from}";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                throw new RuntimeException("Döviz kuru API hatası: HTTP {$httpCode}");
            }
            
            $data = json_decode($response, true);
            
            if (!isset($data['rates'][$to])) {
                throw new RuntimeException("Döviz kuru bulunamadı: {$from} -> {$to}");
            }
            
            $rate = (float) $data['rates'][$to];
            
            // Veritabanına kaydet
            $exchangeRate = $this->em->getRepository(ExchangeRate::class)
                ->findOneBy([
                    'fromCurrency' => $from,
                    'toCurrency' => $to,
                ]);
            
            if (!$exchangeRate) {
                $exchangeRate = new ExchangeRate();
                $exchangeRate->setFromCurrency($from);
                $exchangeRate->setToCurrency($to);
            }
            
            $exchangeRate->setRate((string) $rate);
            $exchangeRate->setUpdatedAt(new DateTimeImmutable());
            
            $this->em->persist($exchangeRate);
            $this->em->flush();
            
            return $rate;
            
        } catch (\Exception $e) {
            // Hata durumunda varsayılan kur döndür (TCMB ortalama)
            if ($from === 'USD' && $to === 'TRY') {
                return 32.50; // Varsayılan USD/TRY kuru
            }
            
            throw new RuntimeException("Döviz kuru güncellenemedi: " . $e->getMessage());
        }
    }
    
    /**
     * Fiyatı dönüştür
     */
    public function convert(float $amount, string $from, string $to): float
    {
        $rate = $this->getRate($from, $to);
        return $amount * $rate;
    }
    
    /**
     * Tüm kurları güncelle
     */
    public function updateAllRates(): void
    {
        $currencies = ['USD', 'EUR', 'GBP', 'TRY'];
        
        foreach ($currencies as $from) {
            foreach ($currencies as $to) {
                if ($from !== $to) {
                    try {
                        $this->updateRate($from, $to);
                    } catch (\Exception $e) {
                        // Hata durumunda devam et
                        error_log("Kur güncellenirken hata: {$from}->{$to}: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

