<?php

declare(strict_types=1);

namespace Orion\Solr;

use JsonException;
use RuntimeException;
use Solarium\Core\Client\Client;
use Solarium\Core\Client\Request;

use function implode;
use function is_array;
use function is_scalar;
use function json_decode;
use function json_encode;
use function rawurlencode;
use function sprintf;
use function trim;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Solr's HTTP API through Solarium's transport: every call is a server request (`/solr/<handler>`), answered as decoded
 * JSON with its status, so the engine decides what each status means. Search parameters travel in a form body:
 * vectors and bound values can be long, and a form keeps repeated parameters (`fq`) repeated.
 */
final readonly class Http
{
    public function __construct(private Client $client)
    {
    }

    /**
     * @param array<string, string|list<string>> $params
     * @return array{int, array<array-key, mixed>}
     */
    public function get(string $handler, array $params = []): array
    {
        $request = $this->request(Request::METHOD_GET, $handler, $params);

        return $this->send($request);
    }

    /**
     * @param array<string, string|list<string>> $params
     * @return array{int, array<array-key, mixed>}
     */
    public function json(string $handler, array $params, mixed $body): array
    {
        $request = $this->request(Request::METHOD_POST, $handler, $params);
        $request->setRawData(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $request->setContentType(Request::CONTENT_TYPE_APPLICATION_JSON);

        return $this->send($request);
    }

    /**
     * @param array<string, string|list<string>> $form
     * @return array{int, array<array-key, mixed>}
     */
    public function form(string $handler, array $form): array
    {
        $request = $this->request(Request::METHOD_POST, $handler, ['wt' => 'json']);
        $pairs = [];
        foreach ($form as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
            }
        }
        $request->setRawData(implode('&', $pairs));
        $request->setContentType(Request::CONTENT_TYPE_APPLICATION_X_WWW_FORM_URLENCODED);

        return $this->send($request);
    }

    /**
     * @param array<string, string|list<string>> $params
     * @return array{int, array<array-key, mixed>}
     */
    public function bytes(string $handler, array $params, string $bytes): array
    {
        $request = $this->request(Request::METHOD_POST, $handler, $params);
        $request->setRawData($bytes);
        $request->setContentType(Request::CONTENT_TYPE_APPLICATION_OCTET_STREAM);

        return $this->send($request);
    }

    /**
     * The answer of a call that must succeed.
     *
     * @param array{int, array<array-key, mixed>} $answer
     * @return array<array-key, mixed>
     * @throws RuntimeException with Solr's own message otherwise
     */
    public static function ok(array $answer, string $what): array
    {
        [$status, $body] = $answer;
        if ($status !== 200) {
            throw new RuntimeException(sprintf('Solr refused to %s (%d): %s', $what, $status, self::message($body)));
        }

        return $body;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function message(array $body): string
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = is_scalar($error['msg'] ?? null) ? (string) $error['msg'] : 'no message';
        // The Schema API says which command failed, and why, in the details.
        $details = [];
        foreach (is_array($error['details'] ?? null) ? $error['details'] : [] as $detail) {
            foreach (is_array($detail) && is_array($detail['errorMessages'] ?? null) ? $detail['errorMessages'] : [] as $reason) {
                $details[] = is_scalar($reason) ? trim((string) $reason) : '';
            }
        }

        return $details === [] ? $message : $message . ': ' . implode('; ', $details);
    }

    /**
     * @param Request::METHOD_GET|Request::METHOD_POST $method
     * @param array<string, string|list<string>> $params
     */
    private function request(string $method, string $handler, array $params): Request
    {
        $request = new Request();
        $request->setMethod($method);
        $request->setHandler($handler);
        $request->setIsServerRequest(true);
        $request->addParams(['wt' => 'json', ...$params]);

        return $request;
    }

    /**
     * @return array{int, array<array-key, mixed>}
     */
    private function send(Request $request): array
    {
        $response = $this->client->getAdapter()->execute($request, $this->client->getEndpoint());
        try {
            $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = ['error' => ['msg' => sprintf('Solr answered %d without JSON.', $response->getStatusCode())]];
        }

        return [$response->getStatusCode(), is_array($body) ? $body : []];
    }
}
