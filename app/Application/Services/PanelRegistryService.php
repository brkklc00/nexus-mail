<?php

declare(strict_types=1);

namespace App\Application\Services;

/**
 * Kardeş panellerin kaydı (paneller arası sipariş taşıma için).
 *
 * .env'de SIBLING_PANELS JSON dizisi olarak tutulur; her panel DİĞER
 * panelleri (ve onların API_TOKEN'larını) listeler:
 *
 *   SIBLING_PANELS='[{"key":"mail1","label":"Mail-1","url":"https://mail-1.hub-nexus.com","token":"<mail-1 API_TOKEN>"}]'
 */
class PanelRegistryService
{
    /**
     * @return list<array{key: string, label: string, url: string, token: string}>
     */
    public function all(): array
    {
        $raw = $_ENV['SIBLING_PANELS'] ?? getenv('SIBLING_PANELS');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $panels = [];
        foreach ($decoded as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $url = rtrim(trim((string) ($entry['url'] ?? '')), '/');
            $token = trim((string) ($entry['token'] ?? ''));
            if ($url === '' || $token === '') {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ('panel' . $index)));
            $label = trim((string) ($entry['label'] ?? $url));

            $panels[] = [
                'key' => $key !== '' ? $key : ('panel' . $index),
                'label' => $label !== '' ? $label : $url,
                'url' => $url,
                'token' => $token,
            ];
        }

        return $panels;
    }

    /**
     * @return array{key: string, label: string, url: string, token: string}|null
     */
    public function get(string $key): ?array
    {
        foreach ($this->all() as $panel) {
            if ($panel['key'] === $key) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * Arayüze gönderilecek token'sız liste.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public function publicList(): array
    {
        return array_map(
            static fn (array $p): array => ['key' => $p['key'], 'label' => $p['label'], 'url' => $p['url']],
            $this->all()
        );
    }
}
