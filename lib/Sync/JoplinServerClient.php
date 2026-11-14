<?php
namespace OCA\Joplix\Sync;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/**
 * Minimal Joplin Server REST client covering what a sync client needs.
 *
 * Mirrors packages/lib/JoplinServerApi.ts and file-api-driver-joplinServer.ts
 * of the Joplin project: session login, item content, delta, children, locks.
 */
class JoplinServerClient {
    private const MIN_VERSION = '2.6.0';
    private const LOCK_TYPE_SYNC = 1;
    private const LOCK_CLIENT_CLI = 3;
    /** Item names this client is willing to address. Everything else is refused. */
    private const NAME_PATTERN = '/^(?:[a-f0-9]{32}\.md|\.resource\/[a-f0-9]{32}|info\.json)$/';

    private IClientService $clientService;
    private string $baseUrl;
    private string $email;
    private string $password;
    private bool $allowLocal;
    private string $sessionId;

    public function __construct(IClientService $clientService, string $baseUrl, string $email, string $password, bool $allowLocal = false, string $sessionId = '') {
        $this->clientService = $clientService;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->email = $email;
        $this->password = $password;
        $this->allowLocal = $allowLocal;
        $this->sessionId = $sessionId;
    }

    /**
     * Validates and normalises a user supplied server address.
     * @throws \InvalidArgumentException
     */
    public static function normalizeBaseUrl(string $url): string {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('The server address is not a valid URL.');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('The server address must start with http:// or https://.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('The server address must not contain credentials.');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('The server address must not contain a query or fragment.');
        }
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        if (preg_match('/[^A-Za-z0-9._~\/-]/', $path)) {
            throw new \InvalidArgumentException('The server address contains unsupported characters.');
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . '://' . strtolower($parts['host']) . $port . $path;
    }

    public function sessionId(): string {
        return $this->sessionId;
    }

    public function login(): void {
        $response = $this->send('post', 'api/sessions', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => $this->email, 'password' => $this->password]),
        ], false);
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'Login failed');
        }
        $data = json_decode($this->text($response), true);
        $id = is_array($data) ? (string)($data['id'] ?? '') : '';
        if ($id === '') {
            throw new JoplinServerException('Login failed: the server did not return a session. Is this a Joplin Server address?', $response->getStatusCode());
        }
        $this->sessionId = $id;
    }

    /** @return array{items: array, cursor: string, has_more: bool} */
    public function delta(string $cursor): array {
        $query = $cursor !== '' ? '?cursor=' . rawurlencode($cursor) : '';
        $data = $this->json('get', 'api/items/root:/:/delta' . $query, 'Reading changes failed');
        return [
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
            'cursor' => (string)($data['cursor'] ?? $cursor),
            'has_more' => !empty($data['has_more']),
        ];
    }

    /** @return array{items: array, cursor: string, has_more: bool} */
    public function children(string $cursor): array {
        $query = $cursor !== '' ? '?cursor=' . rawurlencode($cursor) : '';
        $data = $this->json('get', 'api/items/root:/:/children' . $query, 'Listing items failed');
        return [
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
            'cursor' => (string)($data['cursor'] ?? ''),
            'has_more' => !empty($data['has_more']),
        ];
    }

    public function exists(string $name): bool {
        $response = $this->send('get', $this->itemPath($name));
        if ($response->getStatusCode() === 404) {
            return false;
        }
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'Checking ' . $name . ' failed');
        }
        return true;
    }

    /** Item content as a string, or null when the item does not exist. */
    public function getText(string $name): ?string {
        $response = $this->send('get', $this->itemPath($name) . '/content');
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'Download of ' . $name . ' failed');
        }
        return $this->text($response);
    }

    /**
     * Item content as a readable stream, or null when the item does not exist.
     * @return resource|null
     */
    public function getStream(string $name) {
        $response = $this->send('get', $this->itemPath($name) . '/content', ['stream' => true, 'timeout' => 600]);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'Download of ' . $name . ' failed');
        }
        $body = $response->getBody();
        if (is_resource($body)) {
            return $body;
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, (string)$body);
        rewind($stream);
        return $stream;
    }

    /**
     * Uploads item content.
     * @param string|resource $body
     */
    public function put(string $name, $body): array {
        $response = $this->send('put', $this->itemPath($name) . '/content', [
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'body' => $body,
            'timeout' => 600,
        ]);
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'Upload of ' . $name . ' failed');
        }
        $data = json_decode($this->text($response), true);
        return is_array($data) ? $data : [];
    }

    /** Deletes an item. A missing item is not an error. */
    public function delete(string $name): void {
        $response = $this->send('delete', $this->itemPath($name));
        $status = $response->getStatusCode();
        if ($status !== 200 && $status !== 204 && $status !== 404) {
            $this->fail($response, 'Deleting ' . $name . ' failed');
        }
    }

    /** Acquires or refreshes the sync lock for this client. */
    public function acquireSyncLock(string $clientId): void {
        $response = $this->send('post', 'api/locks', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['type' => self::LOCK_TYPE_SYNC, 'clientType' => self::LOCK_CLIENT_CLI, 'clientId' => $clientId]),
        ]);
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'Could not acquire the sync lock');
        }
    }

    public function releaseSyncLock(string $clientId): void {
        $response = $this->send('delete', 'api/locks/' . self::LOCK_TYPE_SYNC . '_' . self::LOCK_CLIENT_CLI . '_' . rawurlencode($clientId));
        $status = $response->getStatusCode();
        if ($status !== 200 && $status !== 204 && $status !== 404) {
            $this->fail($response, 'Could not release the sync lock');
        }
    }

    private function itemPath(string $name): string {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('Refusing to address unexpected item name: ' . $name);
        }
        return 'api/items/root:/' . $name . ':';
    }

    private function json(string $method, string $path, string $context): array {
        $response = $this->send($method, $path);
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, $context);
        }
        $data = json_decode($this->text($response), true);
        if (!is_array($data)) {
            throw new JoplinServerException($context . ': unexpected response from the server.', $response->getStatusCode());
        }
        return $data;
    }

    private function send(string $method, string $path, array $options = [], bool $auth = true, bool $mayRelogin = true): IResponse {
        $headers = $options['headers'] ?? [];
        $headers['X-API-MIN-VERSION'] = self::MIN_VERSION;
        if ($auth) {
            if ($this->sessionId === '') {
                $this->login();
            }
            $headers['X-API-AUTH'] = $this->sessionId;
        }
        $requestOptions = $options;
        $requestOptions['headers'] = $headers;
        $requestOptions['http_errors'] = false;
        // Never follow redirects: the session header must not travel to another host.
        $requestOptions['allow_redirects'] = false;
        $requestOptions['timeout'] = $options['timeout'] ?? 60;
        if ($this->allowLocal) {
            $requestOptions['nextcloud'] = ['allow_local_address' => true];
        }
        try {
            $response = $this->clientService->newClient()->{$method}($this->baseUrl . '/' . $path, $requestOptions);
        } catch (\Throwable $e) {
            $response = (is_callable([$e, 'getResponse'])) ? $e->getResponse() : null;
            if (!$response instanceof IResponse) {
                throw $this->transportError($e);
            }
        }
        if ($auth && $mayRelogin && $response->getStatusCode() === 403) {
            // The cached session expired or was revoked. Log in again and retry once.
            $this->sessionId = '';
            if (isset($options['body']) && is_resource($options['body'])) {
                @rewind($options['body']);
            }
            return $this->send($method, $path, $options, true, false);
        }
        return $response;
    }

    private function transportError(\Throwable $e): JoplinServerException {
        $chain = '';
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $chain .= get_class($x) . ' ' . $x->getMessage() . ' ';
        }
        if (stripos($chain, 'LocalServerException') !== false || stripos($chain, 'violates local access rules') !== false) {
            return new JoplinServerException(
                'Nextcloud blocked the connection because the server address is on a local or private network. '
                . 'An administrator can allow this in the Joplix synchronisation settings.',
                0,
                'localAddressBlocked'
            );
        }
        return new JoplinServerException('Connection to Joplin Server failed: ' . $e->getMessage(), 0, 'connection');
    }

    private function text(IResponse $response): string {
        $body = $response->getBody();
        if (is_resource($body)) {
            $content = stream_get_contents($body);
            return $content === false ? '' : $content;
        }
        return (string)$body;
    }

    /** @return never */
    private function fail(IResponse $response, string $context): void {
        $status = $response->getStatusCode();
        $text = substr($this->text($response), 0, 2048);
        $data = json_decode($text, true);
        $code = '';
        if (is_array($data) && isset($data['error'])) {
            $message = is_string($data['error']) ? $data['error'] : json_encode($data['error']);
            $code = isset($data['code']) && is_string($data['code']) ? $data['code'] : '';
        } elseif ($status >= 300 && $status < 400) {
            $message = 'the server answered with a redirect (HTTP ' . $status . '). Use the final address of the Joplin Server.';
        } else {
            $message = 'HTTP ' . $status;
        }
        throw new JoplinServerException($context . ': ' . $message, $status, $code);
    }
}
