<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Controller;

use OCA\FacewrightBridge\Service\DetectionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\Image;
use OCP\IPreview;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Serves one face as a square crop, for the People page's grids.
 *
 * A plain route rather than OCS because it backs <img src>, which can't send OCS headers or a
 * request token - hence #[NoCSRFRequired], safe because it is a GET with no side effects. The
 * session user is still the only authority: the detection must be theirs and one of our own rows
 * (never a legacy 128-dim one), and the file is read through their own folder, so a share they've
 * lost or a file they can't open is a 404, not a leak.
 */
class FacePreviewController extends Controller {
	/** Longest edge of the preview the crop is cut from. */
	private const SOURCE_SIZE = 1024;

	/** The crop is the face's longer side times this, so some of the head and hair shows. */
	private const PADDING = 1.5;

	private const MIN_SIZE = 64;
	private const MAX_SIZE = 512;
	private const CACHE_SECONDS = 7 * 24 * 3600;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private IRootFolder $rootFolder,
		private IPreview $preview,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function preview(int $id, int $size = 256): Response {
		if ($this->userId === null) {
			return new NotFoundResponse();
		}
		$size = max(self::MIN_SIZE, min(self::MAX_SIZE, $size));

		$qb = $this->db->getQueryBuilder();
		$qb->select('d.file_id', 'd.x', 'd.y', 'd.width', 'd.height')
			->from('recognize_face_detections', 'd')
			->where($qb->expr()->eq('d.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('d.user_id', $qb->createNamedParameter($this->userId)))
			->andWhere(DetectionService::ownRowsExpr($qb, 'd'));
		$face = $qb->executeQuery()->fetchAssociative();
		if ($face === false) {
			return new NotFoundResponse();
		}

		try {
			$file = $this->rootFolder->getUserFolder($this->userId)->getFirstNodeById((int)$face['file_id']);
			if (!$file instanceof File) {
				return new NotFoundResponse();
			}

			// The ETag needs only the file's own etag, so a revalidation is answered before any
			// preview is generated or decoded.
			$etag = md5($id . '|' . $file->getEtag() . '|' . $size . '|' . $face['x'] . $face['y'] . $face['width'] . $face['height']);
			if ($this->request->getHeader('If-None-Match') === '"' . $etag . '"') {
				$response = new Response();
				$response->setStatus(Http::STATUS_NOT_MODIFIED);
				$response->setETag($etag);
				return $response;
			}

			$source = $this->preview->getPreview($file, self::SOURCE_SIZE, self::SOURCE_SIZE, false);
			[$data, $mime] = $this->crop($source->getContent(), $face, $size);
		} catch (\OCP\Files\NotFoundException) {
			return new NotFoundResponse();
		} catch (\Throwable $e) {
			// A file that can't be previewed or decoded must not turn a whole grid into 500s.
			$this->logger->warning("facewright: no face preview for detection {$id}: " . $e->getMessage(), ['app' => $this->appName, 'exception' => $e]);
			return new NotFoundResponse();
		}

		$response = new DataDisplayResponse($data, Http::STATUS_OK, ['Content-Type' => $mime]);
		$response->cacheFor(self::CACHE_SECONDS, false, false);
		$response->setETag($etag);
		return $response;
	}

	/**
	 * Cuts a square around the face's box (normalized top-left + size) out of the image. The
	 * square is moved, and if need be shrunk, to stay inside the image rather than padded with
	 * black, so a face at the edge is simply off-centre.
	 *
	 * @param array{x: mixed, y: mixed, width: mixed, height: mixed} $face
	 * @return array{0: string, 1: string} [bytes, mime type]
	 */
	private function crop(string $content, array $face, int $size): array {
		$img = new Image();
		if ($img->loadFromData($content) === false) {
			throw new \RuntimeException('Could not decode the preview');
		}
		$iw = (float)$img->width();
		$ih = (float)$img->height();
		if ($iw <= 0 || $ih <= 0) {
			throw new \RuntimeException('Invalid preview size');
		}

		$w = (float)$face['width'];
		$h = (float)$face['height'];
		$side = min(max($w * $iw, $h * $ih) * self::PADDING, $iw, $ih);
		$cx = ((float)$face['x'] + $w / 2.0) * $iw;
		$cy = ((float)$face['y'] + $h / 2.0) * $ih;
		$left = max(0.0, min($cx - $side / 2.0, $iw - $side));
		$top = max(0.0, min($cy - $side / 2.0, $ih - $side));

		if (!$img->crop((int)$left, (int)$top, (int)$side, (int)$side)) {
			throw new \RuntimeException('Could not crop the preview');
		}
		$img->scaleDownToFit($size, $size);

		$data = $img->data();
		$mime = $img->mimeType();
		if ($data === null || $mime === null) {
			throw new \RuntimeException('Could not encode the crop');
		}
		return [$data, $mime];
	}
}
