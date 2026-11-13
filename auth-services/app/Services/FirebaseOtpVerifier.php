<?php

namespace App\Services;

use App\Exceptions\FirebaseVerificationException;
use App\Models\Users;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FirebaseOtpVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    private const CERTS_CACHE_KEY = 'firebase_google_x509_certs';
    private const REPLAY_TTL_SECONDS = 600;

    /**
     * Verify a Firebase ID token, enforce replay protection, and confirm phone ownership.
     *
     * @throws FirebaseVerificationException
     */
    public function verify(string $firebaseToken, Users $actorUser): string
    {
        $replayKey = 'firebase_otp_used:' . hash('sha256', $firebaseToken);
        if (Cache::has($replayKey)) {
            throw new FirebaseVerificationException(
                'Security check failed: Firebase verification token has already been used.',
                403
            );
        }

        $claims = $this->extractAndVerifyClaims($firebaseToken);

        $firebasePhone = $claims['phone_number'] ?? null;
        if (!is_string($firebasePhone) || trim($firebasePhone) === '') {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }

        if (!$this->phonesMatch($firebasePhone, $actorUser)) {
            throw new FirebaseVerificationException(
                'Security check failed: Invalid Firebase verification token or phone mismatch.',
                403
            );
        }

        Cache::put($replayKey, true, now()->addSeconds(self::REPLAY_TTL_SECONDS));

        return $firebasePhone;
    }

    /**
     * Decode and cryptographically verify the Firebase ID token claims.
     *
     * @return array<string, mixed>
     *
     * @throws FirebaseVerificationException
     */
    protected function extractAndVerifyClaims(string $firebaseToken): array
    {
        if (app()->bound('Kreait\Firebase\Contract\Auth')) {
            try {
                $firebaseAuth = app('Kreait\Firebase\Contract\Auth');
                $verifiedIdToken = $firebaseAuth->verifyIdToken($firebaseToken);
                $claims = $verifiedIdToken->claims()->all();
                $this->validateTimestampClaims($claims);

                return $claims;
            } catch (FirebaseVerificationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new FirebaseVerificationException(
                    'Invalid or expired Firebase verification token.',
                    422
                );
            }
        }

        $parts = explode('.', $firebaseToken);
        if (count($parts) !== 3) {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $claims = json_decode($this->base64UrlDecode($payloadB64), true);
        $signature = $this->base64UrlDecode($signatureB64);

        if (!is_array($header) || !is_array($claims) || $signature === '') {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }

        if (($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }

        $certificate = $this->resolveCertificate((string) $header['kid']);
        if (!$certificate) {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }

        $verified = openssl_verify(
            $headerB64 . '.' . $payloadB64,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }

        $this->validateStandardFirebaseClaims($claims);
        $this->validateTimestampClaims($claims);

        return $claims;
    }

    /**
     * Validate Firebase issuer, audience, and subject claims.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function validateStandardFirebaseClaims(array $claims): void
    {
        $sub = $claims['sub'] ?? null;
        $iss = $claims['iss'] ?? null;
        $aud = $claims['aud'] ?? null;

        if (!is_string($sub) || $sub === '' || !is_string($iss) || !is_string($aud) || $aud === '') {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }

        $projectId = config('services.firebase.project_id');
        if (!empty($projectId)) {
            if ($aud !== $projectId || $iss !== 'https://securetoken.google.com/' . $projectId) {
                throw new FirebaseVerificationException(
                    'Invalid or expired Firebase verification token.',
                    422
                );
            }
        } elseif (!str_starts_with($iss, 'https://securetoken.google.com/')) {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }
    }

    /**
     * Validate expiration and replay freshness window (10 minutes).
     *
     * @param  array<string, mixed>  $claims
     */
    protected function validateTimestampClaims(array $claims): void
    {
        $now = time();
        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;

        if (!is_numeric($exp) || (int) $exp <= $now) {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }

        if (!is_numeric($iat) || (int) $iat > ($now + 60) || ($now - (int) $iat) > self::REPLAY_TTL_SECONDS) {
            throw new FirebaseVerificationException(
                'Invalid or expired Firebase verification token.',
                422
            );
        }
    }

    /**
     * Fetch and cache Google's Firebase public x509 certificates.
     */
    protected function resolveCertificate(string $kid): ?string
    {
        $certs = Cache::get(self::CERTS_CACHE_KEY);

        if (!is_array($certs) || !isset($certs[$kid])) {
            $certs = $this->fetchCertificates();
            if (!empty($certs)) {
                Cache::put(self::CERTS_CACHE_KEY, $certs, now()->addHour());
            }
        }

        return isset($certs[$kid]) && is_string($certs[$kid]) ? $certs[$kid] : null;
    }

    /**
     * @return array<string, string>
     */
    protected function fetchCertificates(): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get(self::CERTS_URL);
            if (!$response->successful()) {
                return [];
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Confirm that the Firebase-verified phone number matches the actor admin's registered phone.
     */
    protected function phonesMatch(string $firebasePhone, Users $actorUser): bool
    {
        $countryDigits = preg_replace('/[^0-9]/', '', (string) $actorUser->country_code);
        $phoneDigits = preg_replace('/[^0-9]/', '', (string) $actorUser->phone_number);

        if ($phoneDigits === '') {
            return false;
        }

        $firebaseNormalized = '+' . preg_replace('/[^0-9]/', '', $firebasePhone);
        $expectedStripped = '+' . $countryDigits . ltrim($phoneDigits, '0');
        $expectedRaw = '+' . $countryDigits . $phoneDigits;
        $expectedDirect = '+' . $phoneDigits;

        return $firebaseNormalized === $expectedStripped
            || $firebaseNormalized === $expectedRaw
            || $firebaseNormalized === $expectedDirect;
    }

    protected function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder > 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}

