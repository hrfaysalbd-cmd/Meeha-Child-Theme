<?php
/**
 * Settings tab "নিউজ এলার্ট".
 *
 * @package meeha
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status line for the tab description (subscribers, server support, last send).
 *
 * @return string HTML.
 */
function meeha_push_status_html() {
	if ( ! is_admin() || ! isset( $_GET['page'] ) || 'meeha-theme' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		return '';
	}
	$out = array();
	$ok  = meeha_push_supported();
	$out[] = $ok ? '✅ সার্ভার প্রস্তুত (openssl P-256 ও AES-GCM আছে)।' : '⚠️ এই সার্ভারের PHP-তে openssl-এর P-256/AES-GCM নেই — হোস্টিংকে openssl চালু করতে বলুন; ততক্ষণ প্রশ্ন-কার্ড দেখাবে না।';
	$out[] = 'এখন গ্রাহক: <strong>' . esc_html( meeha_bn_digits( meeha_push_count() ) ) . '</strong> টি ব্রাউজার।';
	$last = get_option( 'meeha_push_last', array() );
	if ( is_array( $last ) && ! empty( $last['post'] ) ) {
		$out[] = sprintf(
			'শেষ পাঠানো: "%s" — %s টি ব্রাউজারে পৌঁছেছে, %s টি বাতিল ঠিকানা মুছে ফেলা হয়েছে।',
			esc_html( get_the_title( (int) $last['post'] ) ),
			esc_html( meeha_bn_digits( (int) $last['sent'] ) ),
			esc_html( meeha_bn_digits( (int) $last['gone'] ) )
		);
	}
	$out[] = 'হাতে পাঠাতে: পোস্ট › সব পোস্ট-এ খবরের নিচে "নিউজ এলার্ট পাঠান" লিংক। iPhone/iPad-এ শুধু হোম-স্ক্রিনে যোগ করা সাইটে এলার্ট কাজ করে (Apple-এর নিয়ম), তাই সেখানে কার্ড দেখায় না।';
	return implode( '<br>', $out );
}

meeha_register_settings_tab(
	'alerts',
	array(
		'title'  => 'নিউজ এলার্ট',
		'order'  => 45,
		'cap'    => 'design',
		'desc'   => meeha_push_status_html(),
		'fields' => array(
			array( 'key' => 'enabled', 'type' => 'checkbox', 'label' => 'নিউজ এলার্ট চালু ("পেতে চান?" কার্ড দেখান)', 'default' => 1 ),
			array( 'key' => 'question', 'type' => 'text', 'label' => 'প্রশ্ন', 'default' => 'মিহা নিউজের নিউজ এলার্ট পেতে চান?' ),
			array( 'key' => 'yes', 'type' => 'text', 'label' => 'সম্মতির বোতাম', 'default' => 'হ্যাঁ' ),
			array( 'key' => 'no', 'type' => 'text', 'label' => 'অসম্মতির বোতাম', 'default' => 'না' ),
			array( 'key' => 'thanks', 'type' => 'text', 'label' => 'সম্মতির পর ধন্যবাদ', 'default' => 'ধন্যবাদ! গুরুত্বপূর্ণ খবর এলেই জানিয়ে দেব।' ),
			array( 'key' => 'icon', 'type' => 'image', 'label' => 'কার্ড ও নোটিফিকেশনের আইকন', 'desc' => 'বর্গাকার, অন্তত ১৯২ × ১৯২। ফাঁকা = সাইট আইকন (কাস্টমাইজার › সাইট আইডেন্টিটি), তা-ও না থাকলে থিমের আইকন।' ),
			array( 'key' => 'delay', 'type' => 'number', 'label' => 'পাতা খোলার কতক্ষণ পর কার্ড দেখাবে', 'default' => 6, 'min' => 0, 'max' => 120, 'unit' => 'সেকেন্ড' ),
			array( 'key' => 'snooze', 'type' => 'number', 'label' => '"না" বললে আবার জিজ্ঞেস করবে', 'default' => 7, 'min' => 1, 'max' => 365, 'unit' => 'দিন পর' ),
			array( 'type' => 'heading', 'label' => 'কোন খবরে এলার্ট যাবে' ),
			array(
				'key'     => 'auto',
				'type'    => 'radio',
				'label'   => 'প্রকাশ হলেই স্বয়ংক্রিয়ভাবে',
				'default' => 'breaking',
				'options' => array(
					'all'      => 'প্রতিটি নতুন খবর',
					'breaking' => 'শুধু ব্রেকিং চিহ্ন দেওয়া খবর',
					'off'      => 'স্বয়ংক্রিয় নয় — শুধু হাতে পাঠানো',
				),
			),
			array( 'key' => 'hourly_cap', 'type' => 'number', 'label' => 'স্বয়ংক্রিয় এলার্ট ঘণ্টায় সর্বোচ্চ', 'default' => 3, 'min' => 1, 'max' => 30, 'unit' => 'টি', 'desc' => 'বেশি এলার্ট পেলে পাঠক বন্ধ করে দেন। হাতে পাঠানো এলার্টে এই সীমা নেই।' ),
			array( 'key' => 'categories', 'type' => 'categories', 'label' => 'শুধু এই বিভাগগুলোর খবর (স্বয়ংক্রিয়)', 'desc' => 'কিছু না বাছলে সব বিভাগ।' ),
			array( 'key' => 'title_prefix', 'type' => 'text', 'label' => 'নোটিফিকেশনের ওপরের লাইন', 'default' => 'মিহা নিউজ', 'desc' => 'নোটিফিকেশনে প্রথমে এই লাইন, তার নিচে খবরের শিরোনাম। ব্রেকিং হলে সামনে "ব্রেকিং:" বসে।' ),
		),
	)
);
