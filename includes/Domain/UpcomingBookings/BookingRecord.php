<?php
namespace TC_BF\Domain\UpcomingBookings;

if ( ! defined('ABSPATH') ) exit;

/**
 * BookingRecord DTO
 *
 * Immutable-ish data container representing one booking for the
 * Upcoming Bookings operational digest. Built by Service and consumed
 * by Renderer. No business logic — just structured data.
 *
 * One record == one order (not one line item). Multiple participants,
 * rentals, and transport items from the same order are rolled up here.
 *
 * @since 1.x.x
 */
final class BookingRecord {

	/** Category constants — where this record appears in the digest */
	const CATEGORY_STARTS_ON = 'starts_on';   // Booking starts on target date
	const CATEGORY_ACTIVE_ON = 'active_on';   // Booking started earlier, still active on target date

	/** @var int WC order ID */
	public $order_id = 0;

	/** @var string Order number (display, e.g. "#1234") */
	public $order_number = '';

	/** @var string Order status slug (processing, completed, …) */
	public $order_status = '';

	/** @var int[] All WC_Booking IDs linked to this order */
	public $booking_ids = [];

	/** @var string self::CATEGORY_* */
	public $category = self::CATEGORY_STARTS_ON;

	/** @var array{name:string,email:string,phone:string} */
	public $customer = [ 'name' => '', 'email' => '', 'phone' => '' ];

	/**
	 * Event / tour details (one per order; if multiple events in one order
	 * the first is primary and the rest go into $extra_events).
	 *
	 * @var array{id:int,title:string,start:int,end:int,start_fmt:string,end_fmt:string}
	 */
	public $event = [
		'id'        => 0,
		'title'     => '',
		'start'     => 0,
		'end'       => 0,
		'start_fmt' => '',
		'end_fmt'   => '',
	];

	/**
	 * Participants on this order. Each entry:
	 * [
	 *   'name'       => string,
	 *   'bike'       => string,   // model + size
	 *   'bike_size'  => string,
	 *   'pedals'     => string,
	 *   'helmet'     => string,
	 *   'shoes'      => string,
	 *   'height'     => string,
	 *   'rental_type'=> string,
	 * ]
	 *
	 * @var array<int, array>
	 */
	public $participants = [];

	/**
	 * Transport info, if the order contains a transport item.
	 *
	 * @var array{present:bool,type:string,date:string,window:string,zone:string,address:string,price:float}|null
	 */
	public $transport = null;

	/**
	 * Partner attribution, if any.
	 *
	 * @var array{code:string,user_id:int,partner_email:string}|null
	 */
	public $partner = null;

	/** @var string Customer note on the order */
	public $customer_note = '';

	/** @var string Concatenated admin/order notes (most recent first) */
	public $admin_notes = '';

	/**
	 * Payment flag.
	 *
	 * @var array{paid:bool,status_label:string}
	 */
	public $payment = [ 'paid' => false, 'status_label' => '' ];

	/** @var bool Customer confirmation email has been sent */
	public $confirmation_sent = false;

	/**
	 * Issues detected on this booking (shown as ⚠️ badges on top of the card).
	 * Each entry: [ 'code' => 'missing_phone', 'label' => 'Missing phone' ]
	 *
	 * @var array<int, array{code:string,label:string}>
	 */
	public $issues = [];

	/** @var int Priority score (higher = more important) */
	public $priority = 0;

	/**
	 * Gmail enrichment results (populated in Phase 3; empty in Phase 1-2).
	 *
	 * @var array<int, array>
	 */
	public $gmail_hits = [];

	/** @var array Raw debug data — never shown in email, useful for logs */
	public $debug = [];
}
