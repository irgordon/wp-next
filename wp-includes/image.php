<?php
/** Decode complete raster images, remove metadata, and preserve JPEG orientation. */
declare( strict_types=1 );
function wp_image_runtime_supported(): bool
{
	if ( ! extension_loaded( 'gd' ) || ! extension_loaded( 'exif' ) ) {
		return false;
	}
	$info = gd_info();
	return $info['JPEG Support'] && $info['PNG Support'] && $info['GIF Read Support'] && $info['GIF Create Support'] && $info['WebP Support'];
}
/** Inspect animation containers without mistaking compressed image bytes for frames. */
function wp_image_is_animated( string $bytes, string $mime ): bool
{
	if ( 'image/webp' === $mime ) {
		return 'VP8X' === substr( $bytes, 12, 4 ) && 0 !== ( ord( $bytes[20] ?? "\0" ) & 2 );
	}
	if ( 'image/png' === $mime ) {
		for ( $offset = 8; $offset + 12 <= strlen( $bytes ); ) {
			$length = unpack( 'N', substr( $bytes, $offset, 4 ) )[1];
			if ( 'acTL' === substr( $bytes, $offset + 4, 4 ) ) {
				return true;
			}
			$offset += 12 + $length;
		}
	}
	if ( 'image/gif' !== $mime ) {
		return false;
	}
	$length = strlen( $bytes );
	$offset = 13 + ( ( ord( $bytes[10] ?? "\0" ) & 128 ) ? 3 * ( 2 ** ( ( ord( $bytes[10] ) & 7 ) + 1 ) ) : 0 );
	$frames = 0;
	while ( $offset < $length ) {
		$marker = ord( $bytes[ $offset++ ] );
		if ( 59 === $marker ) {
			return false;
		}
		if ( 44 === $marker ) {
			if ( ++$frames > 1 ) {
				return true;
			}
			if ( $offset + 9 > $length ) {
				throw new RuntimeException( 'Truncated GIF.' );
			}
			$flags = ord( $bytes[ $offset + 8 ] );
			$offset += 9 + ( ( $flags & 128 ) ? 3 * ( 2 ** ( ( $flags & 7 ) + 1 ) ) : 0 ) + 1;
		} elseif ( 33 === $marker ) {
			++$offset;
		} else {
			throw new RuntimeException( 'Invalid GIF block.' );
		}
		do {
			if ( $offset >= $length ) {
				throw new RuntimeException( 'Truncated GIF data.' );
			}
			$size = ord( $bytes[ $offset++ ] );
			$offset += $size;
		} while ( $size > 0 );
	}
	throw new RuntimeException( 'Missing GIF trailer.' );
}
/** Returns a private normalized temporary file; caller must unlink it. */
function wp_normalize_image( string $path ): array|WP_Error
{
	$temporary = null;
	try {
		if ( ! wp_image_runtime_supported() ) {
			throw new RuntimeException( 'Image decoder is unavailable.' );
		}
		$size = filesize( $path );
		$mime = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $path );
		$dimensions = @getimagesize( $path );
		$extensions = [
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif'
		];
		if ( false === $size || $size < 1 || $size > 10 * 1024 * 1024 || ! isset( $extensions[ $mime ] ) || false === $dimensions || $dimensions['mime'] !== $mime || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 12000 || $dimensions[1] > 12000 || $dimensions[0] * $dimensions[1] > 12000000 ) {
			throw new RuntimeException( 'Image exceeds format, byte or pixel limits.' );
		}
		$limit = (string) ini_get( 'memory_limit' );
		$memory = '-1' === $limit ? 256 * 1024 * 1024 : (int) ini_parse_quantity( $limit );
		if ( $dimensions[0] * $dimensions[1] * 8 + $size * 2 + 32 * 1024 * 1024 > $memory - memory_get_usage( true ) ) {
			throw new RuntimeException( 'Image exceeds available decoding memory.' );
		}
		$bytes = file_get_contents( $path );
		if ( false === $bytes || wp_image_is_animated( $bytes, $mime ) ) {
			throw new RuntimeException( 'Animated uploads are not supported.' );
		}
		$orientation = 'image/jpeg' === $mime ? (int) ( @exif_read_data( $path )['Orientation'] ?? 1 ) : 1;
		// GD can warn and still return a partially decoded image. Warnings reject the upload.
		set_error_handler( static function ( int $severity, string $message ): never {
			throw new RuntimeException( $message );

		} );
		try {
			$image = imagecreatefromstring( $bytes );
			if ( false === $image || imagesx( $image ) !== $dimensions[0] || imagesy( $image ) !== $dimensions[1] ) {
				throw new RuntimeException( 'The complete image could not be decoded.' );
			}
			$angle = match ( $orientation ) {
				3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 
			};
			if ( 0 !== $angle ) {
				$rotated = imagerotate( $image, $angle, 0 );
				if ( false === $rotated ) {
					throw new RuntimeException( 'Orientation failed.' );
				}
				$image = $rotated;
			}
			if ( in_array( $orientation, [ 2, 4, 5, 7 ], true ) ) {
				imageflip( $image, 4 === $orientation ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL );
			}
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			$temporary = tempnam( WP_VAR_DIR, 'image-' );
			if ( false === $temporary ) {
				throw new RuntimeException( 'Cannot create normalized image.' );
			}
			$written = match ( $mime ) {
				'image/jpeg' => imagejpeg( $image, $temporary, 90 ),
				'image/png' => imagepng( $image, $temporary, 6 ),
				'image/webp' => imagewebp( $image, $temporary, IMG_WEBP_LOSSLESS ),
				'image/gif' => imagegif( $image, $temporary )
			};
			if ( ! $written || filesize( $temporary ) < 1 || filesize( $temporary ) > 10 * 1024 * 1024 ) {
				throw new RuntimeException( 'Normalized image exceeds storage limits.' );
			}
			return [
				'path'      => $temporary,
				'mime'      => $mime,
				'extension' => $extensions[ $mime ],
				'width'     => imagesx( $image ),
				'height'    => imagesy( $image ),
				'size'      => filesize( $temporary )
			];
		} finally {
			restore_error_handler();
		}
	} catch ( Throwable $error ) {
		if ( is_string( $temporary ) && is_file( $temporary ) ) {
			unlink( $temporary );
		}
		return new WP_Error( 'invalid_image', 'Use a complete, nonanimated JPEG, PNG, WebP or GIF under 10 MB and 12 megapixels that fits the server memory limit.' );
	}
}
/** Display sizes derived from a sanitized master. Widths never upscale the master. */
function wp_image_variant_sizes( int $width, int $height ): array
{
	$sizes = [];
	if ( $width > 1200 ) {
		$sizes['large'] = [ 1200, (int) round( $height * 1200 / $width ) ];
	}
	if ( $width > 600 ) {
		$sizes['medium'] = [ 600, (int) round( $height * 600 / $width ) ];
	}
	$side = min( 150, $width, $height );
	$sizes['thumbnail'] = [ $side, $side ];
	return $sizes;
}
/** Crop rectangle (x, y, w, h) for a target box around a focal point given as fractions of the master. */
function wp_image_focal_crop( int $width, int $height, int $target_w, int $target_h, float $fx, float $fy ): array
{
	$scale = max( $target_w / $width, $target_h / $height );
	$crop_w = min( $width, (int) round( $target_w / $scale ) );
	$crop_h = min( $height, (int) round( $target_h / $scale ) );
	$x = (int) round( $fx * $width - $crop_w / 2 );
	$y = (int) round( $fy * $height - $crop_h / 2 );
	return [ max( 0, min( $x, $width - $crop_w ) ), max( 0, min( $y, $height - $crop_h ) ), $crop_w, $crop_h ];
}
/**
 * Applies bounded transforms to a sanitized master file and writes a new normalized
 * temporary file. Operations: 'resize' => [w, h] exact box (from a focal crop when
 * 'focal' => [fx, fy] is present), 'crop' => [x, y, w, h] in master pixels,
 * 'rotate' => 90|180|270 clockwise. The output passes the same byte, pixel and
 * memory limits as intake. Caller unlinks the returned path.
 */
function wp_image_transform( string $source, array $ops ): array|WP_Error
{
	$temporary = null;
	try {
		if ( ! wp_image_runtime_supported() || ! is_file( $source ) ) {
			throw new RuntimeException( 'Image decoder is unavailable.' );
		}
		$size = filesize( $source );
		$mime = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $source );
		$dimensions = @getimagesize( $source );
		$extensions = [
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif'
		];
		if ( false === $size || false === $dimensions || ! isset( $extensions[ $mime ] ) || $dimensions['mime'] !== $mime ) {
			throw new RuntimeException( 'The master image could not be read.' );
		}
		$limit = (string) ini_get( 'memory_limit' );
		$memory = '-1' === $limit ? 256 * 1024 * 1024 : (int) ini_parse_quantity( $limit );
		if ( $dimensions[0] * $dimensions[1] * 8 + $size * 2 + 32 * 1024 * 1024 > $memory - memory_get_usage( true ) ) {
			throw new RuntimeException( 'Image exceeds available decoding memory.' );
		}
		$bytes = file_get_contents( $source );
		if ( false === $bytes || wp_image_is_animated( $bytes, $mime ) ) {
			throw new RuntimeException( 'Animated images are not supported.' );
		}
		set_error_handler( static function ( int $severity, string $message ): never {
			throw new RuntimeException( $message );
		} );
		try {
			$image = imagecreatefromstring( $bytes );
			if ( false === $image ) {
				throw new RuntimeException( 'The master image could not be decoded.' );
			}
			if ( isset( $ops['crop'] ) ) {
				[ $cx, $cy, $cw, $ch ] = array_map( 'intval', $ops['crop'] );
				if ( $cw < 1 || $ch < 1 || $cx < 0 || $cy < 0 || $cx + $cw > imagesx( $image ) || $cy + $ch > imagesy( $image ) ) {
					throw new RuntimeException( 'Crop rectangle is outside the image.' );
				}
				$cropped = imagecrop( $image, [
					'x'      => $cx,
					'y'      => $cy,
					'width'  => $cw,
					'height' => $ch
				] );
				if ( false === $cropped ) {
					throw new RuntimeException( 'Crop failed.' );
				}
				$image = $cropped;
			}
			if ( isset( $ops['rotate'] ) ) {
				$angle = match ( (int) $ops['rotate'] ) {
					90 => -90, 180 => 180, 270 => 90, default => 0 
				};
				if ( 0 !== $angle ) {
					$rotated = imagerotate( $image, $angle, 0 );
					if ( false === $rotated ) {
						throw new RuntimeException( 'Rotation failed.' );
					}
					$image = $rotated;
				}
			}
			if ( isset( $ops['resize'] ) ) {
				[ $tw, $th ] = array_map( 'intval', $ops['resize'] );
				if ( $tw < 1 || $th < 1 || $tw > 12000 || $th > 12000 || $tw * $th > 12000000 ) {
					throw new RuntimeException( 'Variant exceeds pixel limits.' );
				}
				if ( isset( $ops['focal'] ) ) {
					[ $fx, $fy ] = $ops['focal'];
					[ $cx, $cy, $cw, $ch ] = wp_image_focal_crop( imagesx( $image ), imagesy( $image ), $tw, $th, (float) $fx, (float) $fy );
					$cropped = imagecrop( $image, [
						'x'      => $cx,
						'y'      => $cy,
						'width'  => $cw,
						'height' => $ch
					] );
					if ( false === $cropped ) {
						throw new RuntimeException( 'Focal crop failed.' );
					}
					$image = $cropped;
				}
				$scaled = imagescale( $image, $tw, $th );
				if ( false === $scaled ) {
					throw new RuntimeException( 'Resize failed.' );
				}
				$image = $scaled;
			}
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			$temporary = tempnam( WP_VAR_DIR, 'image-' );
			if ( false === $temporary ) {
				throw new RuntimeException( 'Cannot create transformed image.' );
			}
			$written = match ( $mime ) {
				'image/jpeg' => imagejpeg( $image, $temporary, 90 ),
				'image/png' => imagepng( $image, $temporary, 6 ),
				'image/webp' => imagewebp( $image, $temporary, IMG_WEBP_LOSSLESS ),
				'image/gif' => imagegif( $image, $temporary )
			};
			if ( ! $written || filesize( $temporary ) < 1 || filesize( $temporary ) > 10 * 1024 * 1024 ) {
				throw new RuntimeException( 'Transformed image exceeds storage limits.' );
			}
			return [
				'path'      => $temporary,
				'mime'      => $mime,
				'extension' => $extensions[ $mime ],
				'width'     => imagesx( $image ),
				'height'    => imagesy( $image ),
				'size'      => filesize( $temporary )
			];
		} finally {
			restore_error_handler();
		}
	} catch ( Throwable $error ) {
		if ( is_string( $temporary ) && is_file( $temporary ) ) {
			unlink( $temporary );
		}
		return new WP_Error( 'transform_failed', $error->getMessage() );
	}
}
