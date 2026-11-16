<?php
namespace Opencart\Catalog\Controller\Common;
/**
 * Class Chatbot
 *
 * Chatbot tư vấn khách hàng theo kịch bản (không dùng AI).
 * - index(): hiển thị khung chat (được gọi từ common/footer)
 * - reply(): nhận câu hỏi qua POST và trả lời dạng JSON
 *
 * @package Opencart\Catalog\Controller\Common
 */
class Chatbot extends \Opencart\System\Engine\Controller {
	/**
	 * Từ không mang nghĩa tìm kiếm sản phẩm (đã bỏ dấu).
	 */
	private const STOPWORDS = [
		'dan', 'nhac', 'cu', 'cho', 'toi', 'minh', 'em', 'anh', 'chi', 'ban', 'shop', 'oi', 'a', 'ah', 'nhe', 'nha', 'voi', 'va', 'la', 'thi', 'ma',
		'co', 'khong', 'ko', 'k', 'muon', 'mua', 'can', 'tim', 'xem', 'gi', 'nao', 'loai', 'nhung', 'cac', 'mot', 'vai', 'it', 'nhieu', 'bao',
		'gia', 'tien', 're', 'dat', 'tot', 'dep', 'hay', 'nhat', 'cai', 'chiec', 'cay', 'con', 'san', 'pham', 'hang', 'dang', 'duoc', 'nen',
		'tu', 'van', 'giup', 'goi', 'y', 'hoi', 'xin', 'chao', 'hoc', 'choi', 'nguoi', 'moi', 'bat', 'dau', 'de', 'cua', 'o', 'day', 'do', 'nay',
		'khoang', 'duoi', 'tren', 'den', 'trieu', 'tr', 'nghin', 'ngan',
		'i', 'want', 'need', 'buy', 'looking', 'for', 'some', 'any', 'the', 'an', 'is', 'are', 'do', 'you', 'have', 'show', 'me', 'please', 'price', 'cheap', 'best', 'good', 'under', 'around', 'about', 'over', 'million'
	];

	/**
	 * Từ đồng nghĩa (tiếng Anh / cách gọi khác) => từ có trong tên sản phẩm, danh mục.
	 */
	private const SYNONYMS = [
		'drum'      => 'trong',
		'drums'     => 'trong',
		'drumset'   => 'trong',
		'flute'     => 'sao',
		'sax'       => 'saxophone',
		'keyboard'  => 'organ',
		'electric'  => 'dien',
		'digital'   => 'dien',
		'erhu'      => 'nhi',
		'vi'        => 'violin',
		'vicam'     => 'violin',
		'ghita'     => 'guitar',
		'ghi'       => 'guitar',
		'classic'   => 'classical',
		'cello'     => 'cello',
		'bass'      => 'bass',
		'acoustic'  => 'acoustic'
	];

	/**
	 * Index
	 *
	 * @return string
	 */
	public function index(): string {
		$this->load->language('common/chatbot');

		$name = $this->config->get('config_name');

		if (is_file(DIR_IMAGE . html_entity_decode((string)$this->config->get('config_logo'), ENT_QUOTES, 'UTF-8'))) {
			$data['logo'] = $this->config->get('config_url') . 'image/' . $this->config->get('config_logo');
		} else {
			$data['logo'] = '';
		}

		$data['title'] = sprintf($this->language->get('text_title'), $name);
		$data['name'] = $name;

		// Đã escape sẵn để đặt vào thuộc tính data-config trong template
		$data['config'] = htmlspecialchars((string)json_encode([
			'url'     => str_replace('&amp;', '&', $this->url->link('common/chatbot.reply', 'language=' . $this->config->get('config_language'))),
			'welcome' => sprintf($this->language->get('text_welcome'), $name),
			'error'   => $this->language->get('text_error'),
			'chips'   => $this->getDefaultChips(),
			'storage' => 'chatbot_' . $this->config->get('config_language')
		], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

		return $this->load->view('common/chatbot', $data);
	}

	/**
	 * Reply
	 *
	 * @return void
	 */
	public function reply(): void {
		$this->load->language('common/chatbot');

		$message = isset($this->request->post['message']) ? trim((string)$this->request->post['message']) : '';
		$message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');
		$message = mb_substr($message, 0, 300);

		if ($message === '') {
			$json = $this->answer($this->language->get('reply_greeting'));
		} else {
			$json = $this->route($message);
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Chọn câu trả lời phù hợp với câu hỏi.
	 *
	 * @param string $message
	 *
	 * @return array<string, mixed>
	 */
	private function route(string $message): array {
		$text = $this->normalize($message);

		// Câu cảm ơn ngắn
		if (str_word_count($text) <= 6 && $this->hasAny($text, ['cam on', 'thank', 'thanks', 'tks', 'thank you'])) {
			return $this->answer($this->language->get('reply_thanks'));
		}

		// Câu hỏi về dịch vụ
		if ($this->hasAny($text, ['phi ship', 'ship', 'giao hang', 'van chuyen', 'phi giao', 'shipping', 'delivery', 'bao lau thi nhan', 'khi nao nhan'])) {
			return $this->replyShipping();
		}

		if ($this->hasAny($text, ['doi tra', 'doi hang', 'tra hang', 'hoan tien', 'bao hanh', 'return', 'refund', 'warranty', 'exchange'])) {
			return $this->replyReturn();
		}

		if ($this->hasAny($text, ['thanh toan', 'chuyen khoan', 'cod', 'tra tien', 'payment', 'pay'])) {
			return $this->replyPayment();
		}

		if ($this->hasAny($text, ['dat hang', 'cach mua', 'mua nhu the nao', 'mua the nao', 'huong dan mua', 'huong dan', 'how to buy', 'how to order'])) {
			return $this->replyHowToBuy();
		}

		if ($this->hasAny($text, ['dia chi', 'lien he', 'hotline', 'so dien thoai', 'sdt', 'email', 'gio mo cua', 'mo cua', 'o dau', 'address', 'contact', 'phone', 'opening hours'])) {
			return $this->replyContact();
		}

		if ($this->hasAny($text, ['khuyen mai', 'giam gia', 'sale', 'ma giam', 'voucher', 'coupon', 'uu dai', 'discount', 'promotion', 'promo'])) {
			return $this->replyPromo();
		}

		// Tìm sản phẩm theo loại và/hoặc ngân sách
		$budget = $this->parseBudget($text);

		if ($budget) {
			$text = trim(str_replace($budget['match'], ' ', $text));
		}

		$tokens = $this->tokenize($text);

		if ($budget || $tokens) {
			$result = $this->replyProducts($tokens, $budget, $message);

			if ($result) {
				return $result;
			}
		}

		if ($this->hasAny($text, ['tu van', 'chon dan', 'nen mua', 'goi y', 'danh muc', 'co ban gi', 'ban gi', 'loai nao', 'nguoi moi', 'help me choose', 'recommend', 'advise', 'category', 'categories', 'beginner'])) {
			return $this->replyAdvise();
		}

		if ($this->hasAny($text, ['xin chao', 'chao', 'hello', 'hi', 'hey', 'alo', 'ad oi', 'shop oi'])) {
			return $this->answer($this->language->get('reply_greeting'));
		}

		if ($tokens) {
			return $this->answer(sprintf($this->language->get('reply_not_found'), $message, $this->config->get('config_telephone')), [], [], $this->getAdviseChips());
		}

		return $this->answer(sprintf($this->language->get('reply_fallback'), $this->config->get('config_telephone')));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function replyShipping(): array {
		$lines = [$this->language->get('reply_shipping')];

		if ($this->config->get('shipping_flat_status')) {
			$lines[] = sprintf($this->language->get('reply_ship_flat'), $this->formatMoney((float)$this->config->get('shipping_flat_cost')));
		}

		if ($this->config->get('shipping_free_status') && (float)$this->config->get('shipping_free_total') > 0) {
			$lines[] = sprintf($this->language->get('reply_ship_free'), $this->formatMoney((float)$this->config->get('shipping_free_total')));
		}

		if ($this->config->get('shipping_pickup_status')) {
			$lines[] = $this->language->get('reply_ship_pickup');
		}

		$lines[] = $this->language->get('reply_ship_time');

		return $this->answer(implode("\n", $lines), $this->infoLink(['giao', 'delivery', 'van chuyen']));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function replyReturn(): array {
		return $this->answer($this->language->get('reply_return'), $this->infoLink(['doi tra', 'return']));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function replyPayment(): array {
		$lines = [$this->language->get('reply_payment')];

		if ($this->config->get('payment_bank_transfer_status')) {
			$lines[] = $this->language->get('reply_pay_bank');
		}

		if ($this->config->get('payment_cod_status')) {
			$lines[] = $this->language->get('reply_pay_cod');
		}

		return $this->answer(implode("\n", $lines));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function replyHowToBuy(): array {
		return $this->answer($this->language->get('reply_howtobuy'), $this->infoLink(['huong dan', 'purchase', 'how to']));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function replyContact(): array {
		$lines = [sprintf($this->language->get('reply_contact'), $this->config->get('config_name'))];

		foreach (['address' => 'config_address', 'phone' => 'config_telephone', 'email' => 'config_email', 'open' => 'config_open'] as $key => $setting) {
			if ($this->config->get($setting)) {
				$lines[] = sprintf($this->language->get('reply_' . $key), $this->config->get($setting));
			}
		}

		$links = [[
			'text' => $this->language->get('link_contact'),
			'href' => $this->url->link('information/contact', 'language=' . $this->config->get('config_language'))
		]];

		return $this->answer(implode("\n", $lines), $links);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function replyPromo(): array {
		$this->load->model('catalog/product');
		$this->load->model('tool/chatbot');

		$specials = $this->model_catalog_product->getSpecials(['start' => 0, 'limit' => 4]);

		$lines = [$specials ? $this->language->get('reply_promo') : $this->language->get('reply_promo_none')];

		$coupons = $this->model_tool_chatbot->getActiveCoupons();

		$coupon_lines = [];

		foreach ($coupons as $coupon) {
			if ($coupon['type'] == 'P') {
				$amount = rtrim(rtrim(number_format((float)$coupon['discount'], 2, '.', ''), '0'), '.') . '%';
			} else {
				$amount = $this->formatMoney((float)$coupon['discount']);
			}

			$line = sprintf($this->language->get('reply_coupon_item'), $coupon['code'], $amount, date($this->language->get('date_format_short'), strtotime($coupon['date_end'])));

			if ((float)$coupon['total'] > 0) {
				$line .= sprintf($this->language->get('reply_coupon_min'), $this->formatMoney((float)$coupon['total']));
			}

			if ($coupon['logged']) {
				$line .= $this->language->get('reply_coupon_login');
			}

			$coupon_lines[] = $line;
		}

		$links = [[
			'text' => $this->language->get('link_special'),
			'href' => $this->url->link('product/special', 'language=' . $this->config->get('config_language'))
		]];

		$json = $this->answer(implode("\n", $lines), $links, $this->formatProducts($specials));

		if ($coupon_lines) {
			$json['after'] = $this->language->get('reply_coupon') . "\n" . implode("\n", $coupon_lines);
		}

		return $json;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function replyAdvise(): array {
		return $this->answer($this->language->get('reply_advise'), [], [], $this->getAdviseChips());
	}

	/**
	 * Gợi ý sản phẩm theo từ khoá và/hoặc ngân sách.
	 *
	 * @param array<int, string>        $tokens
	 * @param array<string, mixed>|null $budget
	 * @param string                    $message
	 *
	 * @return array<string, mixed>|null null nếu từ khoá không khớp sản phẩm nào
	 */
	private function replyProducts(array $tokens, ?array $budget, string $message): ?array {
		$products = $this->getProductIndex();

		$candidates = [];

		if ($tokens) {
			$best = 0;

			foreach ($products as $product) {
				$score = 0;

				foreach ($tokens as $token) {
					if (strpos($product['name_key'], ' ' . $token) !== false) {
						$score += 2;
					} elseif (strpos($product['category_key'], ' ' . $token) !== false) {
						$score += 1;
					}
				}

				if ($score > 0) {
					$product['score'] = $score;
					$candidates[] = $product;
					$best = max($best, $score);
				}
			}

			$candidates = array_values(array_filter($candidates, fn($product) => $product['score'] == $best));

			if (!$candidates) {
				// Có ngân sách nhưng từ khoá không khớp: bỏ qua từ khoá, chỉ lọc theo giá
				if (!$budget) {
					return null;
				}

				$candidates = $products;
			}
		} else {
			$candidates = $products;
		}

		$links = [];

		if ($budget) {
			$label = $this->budgetLabel($budget);

			$in_range = array_values(array_filter($candidates, fn($product) => $product['final'] >= $budget['min'] && $product['final'] <= $budget['max']));

			if ($in_range) {
				usort($in_range, fn($a, $b) => $a['final'] <=> $b['final']);

				$text = sprintf($this->language->get('reply_products_budget'), $label);
				$list = $in_range;
			} else {
				$target = $budget['max'] < PHP_INT_MAX ? $budget['max'] : $budget['min'];

				usort($candidates, fn($a, $b) => abs($a['final'] - $target) <=> abs($b['final'] - $target));

				$text = sprintf($this->language->get('reply_no_budget'), $label);
				$list = array_slice($candidates, 0, 3);
			}
		} else {
			usort($candidates, fn($a, $b) => $a['final'] <=> $b['final']);

			$text = $this->language->get('reply_products');
			$list = $candidates;
		}

		if (count($list) > 4) {
			$links[] = [
				'text' => $this->language->get('link_more') . ' (' . count($list) . ')',
				'href' => $this->url->link('product/search', 'language=' . $this->config->get('config_language') . '&search=' . urlencode(implode(' ', $tokens) ?: $message))
			];
		}

		return $this->answer($text, $links, $this->formatProducts(array_slice($list, 0, 4)));
	}

	/**
	 * Danh sách sản phẩm kèm chuỗi tìm kiếm đã bỏ dấu và giá cuối cùng (đã gồm thuế, khuyến mãi).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getProductIndex(): array {
		$this->load->model('catalog/product');
		$this->load->model('catalog/category');

		$category_names = [];
		$products = [];

		foreach ($this->model_catalog_product->getProducts(['start' => 0, 'limit' => 500]) as $result) {
			$names = [];

			foreach ($this->model_catalog_product->getCategories((int)$result['product_id']) as $row) {
				$category_id = (int)$row['category_id'];

				if (!isset($category_names[$category_id])) {
					$category = $this->model_catalog_category->getCategory($category_id);

					$category_names[$category_id] = $category ? html_entity_decode($category['name'], ENT_QUOTES, 'UTF-8') : '';
				}

				$names[] = $category_names[$category_id];
			}

			$price = (float)$result['special'] ?: (float)$result['price'];

			$result['name_key'] = ' ' . $this->normalize(html_entity_decode($result['name'], ENT_QUOTES, 'UTF-8') . ' ' . $result['model']) . ' ';
			$result['category_key'] = ' ' . $this->normalize(implode(' ', $names)) . ' ';
			$result['final'] = $this->tax->calculate($price, (int)$result['tax_class_id'], $this->config->get('config_tax'));

			$products[] = $result;
		}

		return $products;
	}

	/**
	 * @param array<int, array<string, mixed>> $results
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function formatProducts(array $results): array {
		$this->load->model('tool/image');

		$show_price = $this->customer->isLogged() || !$this->config->get('config_customer_price');

		$products = [];

		foreach ($results as $result) {
			$image = $result['image'] && is_file(DIR_IMAGE . html_entity_decode($result['image'], ENT_QUOTES, 'UTF-8')) ? $result['image'] : 'placeholder.png';

			$price = '';
			$special = '';

			if ($show_price) {
				$price = $this->currency->format($this->tax->calculate((float)$result['price'], (int)$result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);

				if ((float)$result['special']) {
					$special = $this->currency->format($this->tax->calculate((float)$result['special'], (int)$result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
				}
			}

			$products[] = [
				'name'    => html_entity_decode($result['name'], ENT_QUOTES, 'UTF-8'),
				'thumb'   => $this->model_tool_image->resize($image, 80, 80),
				'price'   => $price,
				'special' => $special,
				'href'    => str_replace('&amp;', '&', $this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $result['product_id']))
			];
		}

		return $products;
	}

	/**
	 * Đọc ngân sách trong câu hỏi, ví dụ: "dưới 5 triệu", "tầm 3tr", "từ 10 đến 20 triệu", "500k".
	 *
	 * @param string $text câu đã bỏ dấu
	 *
	 * @return array<string, mixed>|null
	 */
	private function parseBudget(string $text): ?array {
		$num = '(\d+(?:\.\d+)?)\s*(ty|trieu|tr|cu|m|k|nghin|ngan|million|mil)?';

		if (preg_match('/(?:tu\s+|from\s+)?' . $num . '\s+(?:den|toi|to)\s+' . $num . '/', $text, $m)) {
			$min = $this->toAmount($m[1], $m[2] ?: ($m[4] ?? ''));
			$max = $this->toAmount($m[3], $m[4] ?? '');

			return ['type' => 'range', 'min' => min($min, $max), 'max' => max($min, $max), 'match' => $m[0]];
		}

		if (preg_match('/(?:duoi|it hon|khong qua|toi da|under|below|less than|max)\s+' . $num . '/', $text, $m)) {
			return ['type' => 'under', 'min' => 0, 'max' => $this->toAmount($m[1], $m[2] ?? ''), 'match' => $m[0]];
		}

		if (preg_match('/(?:tren|hon|tu|over|above|more than|from)\s+' . $num . '/', $text, $m)) {
			return ['type' => 'over', 'min' => $this->toAmount($m[1], $m[2] ?? ''), 'max' => PHP_INT_MAX, 'match' => $m[0]];
		}

		if (preg_match('/(?:khoang|tam|gia|around|about)\s+' . $num . '/', $text, $m) || preg_match('/(\d+(?:\.\d+)?)\s*(ty|trieu|tr|cu|m|k|nghin|ngan|million|mil)\b/', $text, $m) || preg_match('/\b(\d{6,})\b/', $text, $m)) {
			$amount = $this->toAmount($m[1], $m[2] ?? '');

			return ['type' => 'around', 'min' => $amount * 0.7, 'max' => $amount * 1.3, 'amount' => $amount, 'match' => $m[0]];
		}

		return null;
	}

	/**
	 * @param string $number
	 * @param string $unit
	 *
	 * @return float
	 */
	private function toAmount(string $number, string $unit): float {
		$value = (float)$number;

		switch ($unit) {
			case 'ty':
				return $value * 1000000000;
			case 'trieu':
			case 'tr':
			case 'cu':
			case 'm':
			case 'million':
			case 'mil':
				return $value * 1000000;
			case 'k':
			case 'nghin':
			case 'ngan':
				return $value * 1000;
		}

		// Không ghi đơn vị: số nhỏ hiểu là triệu ("guitar 5" => 5 triệu)
		return $value < 1000 ? $value * 1000000 : $value;
	}

	/**
	 * @param array<string, mixed> $budget
	 *
	 * @return string
	 */
	private function budgetLabel(array $budget): string {
		switch ($budget['type']) {
			case 'under':
				return sprintf($this->language->get('text_budget_under'), $this->formatMoney($budget['max']));
			case 'over':
				return sprintf($this->language->get('text_budget_over'), $this->formatMoney($budget['min']));
			case 'range':
				return sprintf($this->language->get('text_budget_range'), $this->formatMoney($budget['min']), $this->formatMoney($budget['max']));
			default:
				return sprintf($this->language->get('text_budget_around'), $this->formatMoney($budget['amount']));
		}
	}

	/**
	 * Tách từ khoá tìm sản phẩm (bỏ từ thừa, đổi từ đồng nghĩa).
	 *
	 * @param string $text
	 *
	 * @return array<int, string>
	 */
	private function tokenize(string $text): array {
		$tokens = [];

		foreach (explode(' ', $text) as $word) {
			if (isset(self::SYNONYMS[$word])) {
				$word = self::SYNONYMS[$word];
			}

			if (mb_strlen($word) < 2 || is_numeric($word) || in_array($word, self::STOPWORDS)) {
				continue;
			}

			$tokens[$word] = $word;
		}

		return array_values($tokens);
	}

	/**
	 * Chữ thường, bỏ dấu tiếng Việt, chỉ giữ chữ, số và dấu chấm thập phân.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	private function normalize(string $text): string {
		$text = mb_strtolower($text, 'UTF-8');

		$map = [
			'a' => 'àáạảãâầấậẩẫăằắặẳẵ',
			'e' => 'èéẹẻẽêềếệểễ',
			'i' => 'ìíịỉĩ',
			'o' => 'òóọỏõôồốộổỗơờớợởỡ',
			'u' => 'ùúụủũưừứựửữ',
			'y' => 'ỳýỵỷỹ',
			'd' => 'đ'
		];

		foreach ($map as $plain => $chars) {
			$text = str_replace(mb_str_split($chars), $plain, $text);
		}

		// 5.000.000 / 5,000,000 => 5000000 ; 2,5 => 2.5
		$text = preg_replace_callback('/\d{1,3}(?:[.,]\d{3})+(?![.,]?\d)/', fn($m) => str_replace(['.', ','], '', $m[0]), $text);
		$text = preg_replace('/(\d),(\d)/', '$1.$2', $text);

		$text = preg_replace('/[^a-z0-9.]+/', ' ', $text);
		$text = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $text);

		// 5tr => 5 tr ; 500k => 500 k
		$text = preg_replace('/(\d)([a-z])/', '$1 $2', $text);

		return trim(preg_replace('/\s+/', ' ', $text));
	}

	/**
	 * @param string             $text     câu đã bỏ dấu
	 * @param array<int, string> $keywords từ khoá đã bỏ dấu
	 *
	 * @return bool
	 */
	private function hasAny(string $text, array $keywords): bool {
		$text = ' ' . $text . ' ';

		foreach ($keywords as $keyword) {
			if (strpos($text, ' ' . $keyword . ' ') !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Liên kết tới trang thông tin có tiêu đề chứa một trong các từ khoá.
	 *
	 * @param array<int, string> $keywords
	 *
	 * @return array<int, array<string, string>>
	 */
	private function infoLink(array $keywords): array {
		$this->load->model('catalog/information');

		foreach ($this->model_catalog_information->getInformations() as $information) {
			$title = $this->normalize(html_entity_decode($information['title'], ENT_QUOTES, 'UTF-8'));

			if (array_filter($keywords, fn($keyword) => strpos($title, $keyword) !== false)) {
				return [[
					'text' => $this->language->get('link_policy'),
					'href' => str_replace('&amp;', '&', $this->url->link('information/information', 'language=' . $this->config->get('config_language') . '&information_id=' . $information['information_id']))
				]];
			}
		}

		return [];
	}

	/**
	 * @param float $amount
	 *
	 * @return string
	 */
	private function formatMoney(float $amount): string {
		return $this->currency->format($amount, $this->session->data['currency']);
	}

	/**
	 * @return array<int, string>
	 */
	private function getDefaultChips(): array {
		return [
			$this->language->get('chip_advise'),
			$this->language->get('chip_promo'),
			$this->language->get('chip_shipping'),
			$this->language->get('chip_return'),
			$this->language->get('chip_payment'),
			$this->language->get('chip_contact')
		];
	}

	/**
	 * Gợi ý theo danh mục cấp 1 đang hiển thị.
	 *
	 * @return array<int, string>
	 */
	private function getAdviseChips(): array {
		$this->load->model('catalog/category');

		$chips = [];

		foreach ($this->model_catalog_category->getCategories(0) as $category) {
			$chips[] = html_entity_decode($category['name'], ENT_QUOTES, 'UTF-8');
		}

		return $chips;
	}

	/**
	 * @param string                           $text
	 * @param array<int, array<string,string>> $links
	 * @param array<int, array<string,mixed>>  $products
	 * @param array<int, string>|null          $chips
	 *
	 * @return array<string, mixed>
	 */
	private function answer(string $text, array $links = [], array $products = [], ?array $chips = null): array {
		return [
			'text'     => $text,
			'links'    => $links,
			'products' => $products,
			'chips'    => $chips ?? $this->getDefaultChips()
		];
	}
}
