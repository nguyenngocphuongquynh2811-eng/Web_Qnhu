<?php
namespace Opencart\Catalog\Model\Tool;
/**
 * Class Chatbot
 *
 * Dữ liệu phụ cho chatbot tư vấn (mã giảm giá đang hiệu lực).
 *
 * @package Opencart\Catalog\Model\Tool
 */
class Chatbot extends \Opencart\System\Engine\Model {
	/**
	 * Get Active Coupons
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getActiveCoupons(): array {
		$query = $this->db->query("SELECT `name`, `code`, `type`, `discount`, `logged`, `total`, `date_end` FROM `" . DB_PREFIX . "coupon` WHERE `status` = '1' AND `date_start` <= CURDATE() AND `date_end` >= CURDATE() ORDER BY `date_end` ASC LIMIT 5");

		return $query->rows;
	}
}
