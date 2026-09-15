<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

/**
 * The one place an embedding becomes bytes, for facewright_faces.embedding (Stage 1 of
 * docs/independence-implementation.md). InsightFace already emits float32; packing straight into
 * that width - rather than JSON through a PHP double, as recognize_face_detections.face_vector
 * still does - loses nothing: `1 - dot(decode(encode(v)), v)` for an L2-normalized `v` is <= 1e-7,
 * against a clustering threshold of 0.5.
 *
 * DIMENSIONS/BYTES replace ClusteringService::EXPECTED_DIMENSIONS as the authoritative "is this a
 * real embedding" test for anything read from facewright_faces.
 */
class FaceVectorCodec {
	public const DIMENSIONS = 512;
	public const BYTES = self::DIMENSIONS * 4;

	/** @param list<float> $vector */
	public static function encode(array $vector): string {
		return pack('g*', ...$vector);
	}

	/**
	 * @return list<float>
	 * @throws \UnexpectedValueException if $blob isn't exactly BYTES long
	 */
	public static function decode(string $blob): array {
		if (strlen($blob) !== self::BYTES) {
			throw new \UnexpectedValueException(sprintf(
				'Expected a %d-byte embedding, got %d bytes.',
				self::BYTES,
				strlen($blob),
			));
		}
		return array_values(unpack('g*', $blob));
	}
}
