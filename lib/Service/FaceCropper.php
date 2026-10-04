<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Cuts a person's face out of one specific picture.
 *
 * Immich's own person thumbnail may come from a picture the viewer is not allowed
 * to see, so the app cuts its own from a picture that PeopleIndex picked for this
 * user. Cached in app data by person and picture.
 */
class FaceCropper {
	private const FOLDER = 'faces';
	private const SIZE = 256;
	/** Extra room around the detected box, as a share of its size. */
	private const MARGIN = 0.45;

	public function __construct(
		private ImmichClient $immich,
		private IAppData $appData,
	) {
	}

	/** @return string JPEG bytes */
	public function face(string $personId, string $assetId): string {
		$name = $personId . '_' . $assetId . '.jpg';
		$folder = $this->folder();
		try {
			return $folder->getFile($name)->getContent();
		} catch (NotFoundException) {
		}
		$jpeg = $this->crop($personId, $assetId);
		$folder->newFile($name, $jpeg);
		return $jpeg;
	}

	private function crop(string $personId, string $assetId): string {
		$box = null;
		foreach ($this->immich->json('GET', '/faces?id=' . $assetId) as $face) {
			if (($face['person']['id'] ?? null) === $personId) {
				$box = $face;
				break;
			}
		}
		if ($box === null) {
			throw new ImmichException('Not found', 404);
		}

		$img = @imagecreatefromstring($this->immich->binary('/assets/' . $assetId . '/thumbnail?size=preview')['body']);
		if ($img === false) {
			throw new ImmichException('Not found', 404);
		}
		// Face boxes are in the coordinates of the image Immich analysed; scale to the preview.
		$sx = imagesx($img) / max(1, $box['imageWidth']);
		$sy = imagesy($img) / max(1, $box['imageHeight']);
		$w = ($box['boundingBoxX2'] - $box['boundingBoxX1']) * $sx;
		$h = ($box['boundingBoxY2'] - $box['boundingBoxY1']) * $sy;
		$side = (int)min(max($w, $h) * (1 + 2 * self::MARGIN), imagesx($img), imagesy($img));
		$cx = ($box['boundingBoxX1'] * $sx) + $w / 2;
		$cy = ($box['boundingBoxY1'] * $sy) + $h / 2;
		$x = (int)max(0, min(imagesx($img) - $side, $cx - $side / 2));
		$y = (int)max(0, min(imagesy($img) - $side, $cy - $side / 2));

		$out = imagecreatetruecolor(self::SIZE, self::SIZE);
		imagecopyresampled($out, $img, 0, 0, $x, $y, self::SIZE, self::SIZE, $side, $side);
		ob_start();
		imagejpeg($out, null, 85);
		return (string)ob_get_clean();
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
