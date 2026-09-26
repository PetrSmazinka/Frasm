<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Exceptions\PushException;
use CurlHandle;

/**
 * @file WebPushSender.php
 * @brief Delivers encrypted Web Push messages to push services over HTTPS.
 */

/**
 * @class WebPushSender
 * @brief Sends one encrypted request per subscription, in parallel batches via curl_multi.
 *
 * Requests are HTTPS-only, never follow redirects and have a hard timeout. Endpoints are
 * re-checked against the allowed push service hosts before every request.
 */
class WebPushSender
{
    /**
     * @brief WebPushSender constructor.
     *
     * @param Vapid $vapid Application server identification.
     * @param list<string> $allowedHosts Allowed push service host patterns.
     * @param int $defaultTtl Default TTL in seconds.
     * @param string $defaultUrgency Default urgency.
     * @param int $concurrency Maximum parallel requests.
     * @param int $timeout Per-request timeout in seconds.
     */
    public function __construct(
        protected Vapid $vapid,
        protected array $allowedHosts,
        protected int $defaultTtl = 86400,
        protected string $defaultUrgency = 'normal',
        protected int $concurrency = 10,
        protected int $timeout = 10
    ) {
        if (!extension_loaded('curl')) {
            throw new PushException('Web Push requires the curl PHP extension.');
        }
    }

    /**
     * @brief Returns the VAPID identification used by this sender.
     *
     * @return Vapid
     */
    public function vapid(): Vapid
    {
        return $this->vapid;
    }

    /**
     * @brief Sends a message to the given subscriptions.
     *
     * Invalid subscriptions (bad keys, disallowed host) produce a result with status 0 instead of aborting the batch.
     *
     * @param list<Subscription> $subscriptions Recipients.
     * @param PushMessage $message Notification.
     * @return list<PushResult>
     * @throws PushException If the payload itself is invalid.
     */
    public function send(array $subscriptions, PushMessage $message): array
    {
        $payload = $message->toPayload();
        $results = [];

        foreach (array_chunk($subscriptions, max(1, $this->concurrency)) as $chunk) {
            $multi = curl_multi_init();
            $handles = [];

            foreach ($chunk as $index => $subscription) {
                try {
                    $handles[$index] = $this->createHandle($subscription, $payload, $message);
                    curl_multi_add_handle($multi, $handles[$index]);
                } catch (PushException $e) {
                    $results[] = new PushResult($subscription, 0, $e->getMessage());
                }
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $index => $handle) {
                $httpStatus = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $error = curl_error($handle);
                if ($error === '' && ($httpStatus < 200 || $httpStatus >= 300)) {
                    $error = substr((string)curl_multi_getcontent($handle), 0, 300);
                }

                $results[] = new PushResult($chunk[$index], $httpStatus, $error);
                curl_multi_remove_handle($multi, $handle);
            }

            curl_multi_close($multi);
        }

        return $results;
    }

    /**
     * @brief Builds the encrypted HTTP request for one subscription.
     *
     * @param Subscription $subscription Recipient.
     * @param string $payload JSON payload.
     * @param PushMessage $message Message options (TTL, urgency, topic).
     * @return CurlHandle
     * @throws PushException On invalid subscription data.
     */
    protected function createHandle(Subscription $subscription, string $payload, PushMessage $message): CurlHandle
    {
        Subscription::assertEndpointAllowed($subscription->endpoint, $this->allowedHosts);

        $body = Crypto::encrypt(
            $payload,
            Crypto::base64UrlDecode($subscription->p256dh),
            Crypto::base64UrlDecode($subscription->auth)
        );

        $headers = [
            'Authorization: ' . $this->vapid->authorizationHeader($subscription->endpoint),
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
            'TTL: ' . max(0, $message->ttl ?? $this->defaultTtl),
            'Urgency: ' . ($message->urgency ?? $this->defaultUrgency),
        ];
        if ($message->topic !== null) {
            $headers[] = 'Topic: ' . $message->topic;
        }

        $handle = curl_init($subscription->endpoint);
        if ($handle === false) {
            throw new PushException('Unable to initialize a curl handle.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => min(5, $this->timeout),
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);

        return $handle;
    }
}
