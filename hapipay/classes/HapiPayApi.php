<?php
/**
 * Appels à l'API HapiPay : page de paiement à usage unique, état d'une page,
 * vérification de la clé et de la signature des webhooks.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_') && !defined('HAPIPAY_TESTS')) {
    exit;
}

class HapiPayApi
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    public function __construct($baseUrl, $apiKey)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->apiKey = (string) $apiKey;
    }

    /** Clé de test (hpc_test_…) : tous les paiements sont simulés. */
    public function isTest()
    {
        return strpos($this->apiKey, 'hpc_test_') === 0;
    }

    /** @return array{ok: bool, status: int, data: array, message: string} */
    public function createLink(array $body)
    {
        return $this->request('POST', '/liens', $body);
    }

    public function linkState($code)
    {
        return $this->request('GET', '/liens/' . rawurlencode((string) $code));
    }

    public function whoami()
    {
        return $this->request('GET', '/plateforme');
    }

    private function request($method, $path, array $body = null)
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            return ['ok' => false, 'status' => 0, 'data' => [], 'message' => 'HapiPay n\'est pas configuré : adresse de l\'API et clé requises.'];
        }

        $ch = curl_init($this->baseUrl . $path);
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: HapiPay-PrestaShop/' . (defined('HAPIPAY_PS_VERSION') ? HAPIPAY_PS_VERSION : 'dev'),
        ];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            return ['ok' => false, 'status' => 0, 'data' => [], 'message' => 'HapiPay injoignable : ' . $error];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($raw, true);
        $json = is_array($json) ? $json : [];
        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'data' => isset($json['data']) && is_array($json['data']) ? $json['data'] : [],
            'message' => $ok ? '' : (isset($json['message']) ? (string) $json['message'] : 'HapiPay a répondu ' . $status . '.'),
        ];
    }

    /**
     * Vérifie l'en-tête HapiPay-Signature : t=<horodatage>,v1=<hmac>[,v1=<hmac>].
     * Deux v1 pendant les 24 h qui suivent un changement de secret.
     */
    public static function validSignature($header, $rawBody, $secret, $tolerance = 300)
    {
        if ((string) $secret === '' || (string) $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, (string) $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
