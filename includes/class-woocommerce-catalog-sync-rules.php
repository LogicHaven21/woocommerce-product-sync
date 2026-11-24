<?php
/**
 * قواعد خالص همگام‌سازی؛ این بخش هیچ تماس شبکه یا وابستگی به وردپرس ندارد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WC_Catalog_Sync_Rules {
	public static function product_is_selected( array $product_term_ids, array $root_ids, array $ancestors_by_term = array() ) {
		$root_ids = array_values( array_unique( array_map( 'intval', $root_ids ) ) );
		if ( empty( $root_ids ) ) {
			return false;
		}

		foreach ( array_map( 'intval', $product_term_ids ) as $term_id ) {
			if ( in_array( $term_id, $root_ids, true ) ) {
				return true;
			}
			$ancestors = isset( $ancestors_by_term[ $term_id ] ) ? (array) $ancestors_by_term[ $term_id ] : array();
			foreach ( array_map( 'intval', $ancestors ) as $ancestor_id ) {
				if ( in_array( $ancestor_id, $root_ids, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function exact_sku_matches( $candidates, $sku ) {
		$matches = array();
		if ( ! is_array( $candidates ) ) {
			return $matches;
		}

		foreach ( $candidates as $candidate ) {
			if ( is_object( $candidate ) && isset( $candidate->id, $candidate->sku ) && trim( (string) $candidate->sku ) === trim( (string) $sku ) ) {
				$matches[] = $candidate;
			}
		}

		return $matches;
	}

	public static function regular_price( $regular_price, $current_price ) {
		$regular_price = (string) $regular_price;
		$current_price = (string) $current_price;
		return '' === $regular_price && '' !== $current_price ? $current_price : $regular_price;
	}

	public static function safe_create_rejection( $status ) {
		$status = (int) $status;
		return $status >= 400 && $status < 500 && ! in_array( $status, array( 408, 409 ), true );
	}

	public static function image_only_rejection( $status, $message ) {
		if ( ! in_array( (int) $status, array( 400, 422 ), true ) ) {
			return false;
		}

		$message = (string) $message;
		return false !== stripos( $message, 'image' ) || false !== stripos( $message, 'media' ) || false !== stripos( $message, 'تصویر' );
	}
}
