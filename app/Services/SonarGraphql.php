<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * Minimal client for Sonar's GraphQL API.
 *
 * The rest of the portal talks to Sonar through the v1 REST API (customer_portal_framework);
 * this is only used for the things v1 can't do, such as changing an account's status.
 */
class SonarGraphql
{
    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'connect_timeout' => 5,
            'timeout' => 10,
        ]);
    }

    public function isConfigured(): bool
    {
        return config('customer_portal.sonar_graphql_url') && config('customer_portal.sonar_graphql_token');
    }

    /**
     * Run a query or mutation and return its "data" member.
     *
     * @throws RuntimeException on transport errors, GraphQL errors or a malformed response
     */
    public function query(string $query, array $variables = []): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Sonar GraphQL is not configured (SONAR_GRAPHQL_URL / SONAR_GRAPHQL_TOKEN).');
        }

        $response = $this->client->request('POST', config('customer_portal.sonar_graphql_url'), [
            'headers' => [
                'Authorization' => 'Bearer ' . config('customer_portal.sonar_graphql_token'),
                'Accept' => 'application/json',
            ],
            'json' => ['query' => $query, 'variables' => (object) $variables],
            'http_errors' => true,
        ]);

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            throw new RuntimeException('Sonar GraphQL returned a non-JSON response.');
        }
        if (!empty($body['errors'])) {
            throw new RuntimeException('Sonar GraphQL error: ' . json_encode($body['errors']));
        }
        if (!isset($body['data']) || !is_array($body['data'])) {
            throw new RuntimeException('Sonar GraphQL response has no data.');
        }

        return $body['data'];
    }
}
