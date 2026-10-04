<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\AssetSearch;
use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\ImmichClient;
use OCA\ImmichSearch\Service\ImmichException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ApiController extends Controller {
    private const PAGE_SIZE = 100;
    private const IMAGE_CACHE_SECONDS = 86400;

    public function __construct(
        IRequest $request,
        private ?string $userId,
        private Config $config,
        private AssetSearch $search,
        private ImmichClient $immich,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function search(string $q = '', int $page = 1, string $person = '', string $type = ''): JSONResponse {
        $q = trim($q);
        if ($q === '' && $person === '') {
            return new JSONResponse(['items' => [], 'nextPage' => null]);
        }
        return $this->guard(fn () => new JSONResponse(
            $this->search->search($this->uid(), $q, $page, self::PAGE_SIZE, $person, $type)
        ));
    }

    #[NoAdminRequired]
    public function people(): JSONResponse {
        return $this->guard(fn () => new JSONResponse($this->search->people($this->uid())));
    }

    /** No CSRF check: loaded by <img> tags, which cannot send the request token. Read-only. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function thumbnail(string $id, string $size = 'thumbnail'): Response {
        $size = $size === 'preview' ? 'preview' : 'thumbnail';
        return $this->image('/assets/' . $id . '/thumbnail?size=' . $size, $id);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function personThumbnail(string $id): Response {
        return $this->image('/people/' . $id . '/thumbnail', $id);
    }

    private function image(string $endpoint, string $id): Response {
        if (!preg_match(AssetSearch::UUID, $id)) {
            return new JSONResponse(['error' => 'Ungültige ID'], Http::STATUS_BAD_REQUEST);
        }
        return $this->guard(function () use ($endpoint) {
            $img = $this->immich->binary($this->uid(), $endpoint);
            $resp = new DataDisplayResponse($img['body'], Http::STATUS_OK, ['Content-Type' => $img['type']]);
            $resp->cacheFor(self::IMAGE_CACHE_SECONDS, false);
            return $resp;
        });
    }

    private function uid(): string {
        if ($this->userId === null) {
            throw new ImmichException('Nicht angemeldet', Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->config->isConfigured($this->userId)) {
            throw new ImmichException('Immich ist nicht eingerichtet (Persönliche Einstellungen → Fotosuche)', Http::STATUS_PRECONDITION_FAILED);
        }
        return $this->userId;
    }

    /** Turns ImmichException into a JSON error with its status; anything else is logged as a bug. */
    private function guard(callable $fn): Response {
        try {
            return $fn();
        } catch (ImmichException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getCode() ?: Http::STATUS_BAD_GATEWAY);
        } catch (\Throwable $e) {
            $this->logger->error('Fotosuche: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
            return new JSONResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
