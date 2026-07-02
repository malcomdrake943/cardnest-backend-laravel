<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\Response;

class GatewayController extends Controller
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client([
            'http_errors' => false, // Don't throw exceptions on 4xx/5xx responses
            'timeout' => 30.0,
        ]);
    }

    /**
     * Proxy request to Auth Service
     */
    public function proxyToAuthService(Request $request)
    {
        $url = config('services.auth.url');
        return $this->forward($request, $url);
    }

    /**
     * Proxy request to Subscriptions and Scan Service
     */
    public function proxyToSubscriptionsService(Request $request)
    {
        $url = config('services.subscriptions.url');
        return $this->forward($request, $url);
    }

    /**
     * Return authenticated user details directly from Gateway
     */
    public function getAuthenticatedUser(Request $request)
    {
        return response()->json([
            'status' => true,
            'data' => $request->user()
        ]);
    }

    /**
     * Forward request to target microservice
     */
    protected function forward(Request $request, string $baseUrl)
    {
        $path = $request->path();

        // Map path for Auth Service
        if (str_starts_with($path, 'api/auth/')) {
            $subPath = str_replace('api/auth/', 'api/', $path);
        } else {
            $subPath = $path;
        }

        $targetUrl = rtrim($baseUrl, '/') . '/' . ltrim($subPath, '/');

        // Prevent circular routing to self which causes deadlocks/timeouts
        $targetParts = parse_url($targetUrl);
        $targetHost = $targetParts['host'] ?? '';
        $targetPort = isset($targetParts['port']) ? (int)$targetParts['port'] : (($targetParts['scheme'] ?? 'http') === 'https' ? 443 : 80);

        $requestHost = $request->getHost();
        $requestPort = (int)$request->getPort();

        if ($requestHost === $targetHost && $requestPort === $targetPort) {
            return response()->json([
                'status' => false,
                'message' => 'API Gateway Configuration Error',
                'error' => "Circular routing detected: The Gateway is configured to forward requests to itself ($targetUrl). Please check the environment variables (e.g. AUTH_SERVICE_URL, SUBSCRIPTIONS_SERVICE_URL) on the server."
            ], 500);
        }

        // Determine if request is multipart/form-data
        $contentType = $request->header('Content-Type');
        $isMultipart = str_contains((string) $contentType, 'multipart/form-data') || !empty($request->allFiles());

        // Headers
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $lowerName = strtolower($name);
            // Exclude Host and Content-Length headers to let Guzzle set them
            if (in_array($lowerName, ['host', 'content-length'])) {
                continue;
            }
            // Exclude Content-Type for multipart requests so Guzzle generates the correct boundary
            if ($isMultipart && $lowerName === 'content-type') {
                continue;
            }
            $headers[$name] = $values[0];
        }

        // Add internal service token
        $headers['X-Internal-Service-Token'] = config('services.internal.token');

        // If user is authenticated, attach user context headers
        if ($user = $request->user()) {
            $headers['X-User-ID'] = (string) $user->id;
            $headers['X-User-Role'] = (string) $user->role;
            $headers['X-Merchant-ID'] = (string) $user->merchant_id;
        }

        // Setup Guzzle options
        $options = [
            'headers' => $headers,
            'query' => $request->query(),
        ];

        // Handle request body

        if ($isMultipart) {
            // Build multipart payload for Guzzle
            $multipart = [];
            
            // Add normal fields
            foreach ($request->all() as $key => $value) {
                if (!$request->hasFile($key)) {
                    if (is_array($value)) {
                        foreach ($value as $subKey => $subValue) {
                            $multipart[] = [
                                'name' => $key . '[' . $subKey . ']',
                                'contents' => (string) $subValue,
                            ];
                        }
                    } else {
                        $multipart[] = [
                            'name' => $key,
                            'contents' => (string) $value,
                        ];
                    }
                }
            }

            // Add uploaded files
            foreach ($request->allFiles() as $key => $file) {
                if (is_array($file)) {
                    foreach ($file as $subKey => $subFile) {
                        $multipart[] = [
                            'name' => $key . '[' . $subKey . ']',
                            'contents' => fopen($subFile->getPathname(), 'r'),
                            'filename' => $subFile->getClientOriginalName(),
                            'headers' => [
                                'Content-Type' => $subFile->getMimeType(),
                            ],
                        ];
                    }
                } else {
                    $multipart[] = [
                        'name' => $key,
                        'contents' => fopen($file->getPathname(), 'r'),
                        'filename' => $file->getClientOriginalName(),
                        'headers' => [
                            'Content-Type' => $file->getMimeType(),
                        ],
                    ];
                }
            }

            $options['multipart'] = $multipart;
        } else {
            // JSON or form params
            $contentType = $request->header('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $options['json'] = $request->json()->all();
            } else {
                $options['form_params'] = $request->all();
            }
        }

        try {
            $response = $this->client->request($request->method(), $targetUrl, $options);
            
            // Extract response body
            $body = $response->getBody()->getContents();
            $statusCode = $response->getStatusCode();
            
            // Create the response object
            $laravelResponse = response($body, $statusCode);

            // Forward response headers (excluding standard connection headers)
            foreach ($response->getHeaders() as $name => $values) {
                if (!in_array(strtolower($name), ['transfer-encoding', 'connection', 'content-length', 'content-encoding'])) {
                    $laravelResponse->header($name, $values[0]);
                }
            }

            return $laravelResponse;
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'API Gateway Forwarding Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
